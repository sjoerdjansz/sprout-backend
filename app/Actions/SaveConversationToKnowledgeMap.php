<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\MaterialStatus;
use App\Enums\MessageRole;
use App\Jobs\SplitSourcesOverSubtopics;
use App\Models\Material;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves the reviewed draft of a conversation as a topic in the knowledge map with the files sent in it,
 * queues flashcards and questions for what is new (after splitting those files over the subtopics), and
 * ends the conversation. Continuing a topic adds to the same topic.
 * The AI isn't called here: the material is made in the background (specs/background-generation.md).
 */
class SaveConversationToKnowledgeMap
{
    public const CATEGORY_REQUIRED_MESSAGE = 'Pick a category for this topic.';

    public function __construct(
        private QueueMaterialGeneration $queueMaterialGeneration,
        private ResolveCategory $resolveCategory,
    ) {}

    /**
     * @param  array<int, array{title: string, content: string}>  $subtopics  as reviewed by the learner
     * @param  int|null  $categoryId  one of the learner's categories; with $categoryName, required for a new topic
     * @param  string|null  $categoryName  a new category, or an existing one by name
     * @return array{topic: Topic, added_subtopics: int}
     *
     * @throws ValidationException when the conversation is already saved, or a new topic has no category.
     */
    public function handle(
        StudySession $conversation,
        string $title,
        string $goal,
        string $goalDeadline,
        int $goalRecallPercentage,
        array $subtopics,
        ?int $categoryId,
        ?string $categoryName,
    ): array {
        if ($conversation->isSaved()) {
            throw ValidationException::withMessages(['conversation' => SendMessageToTutor::ALREADY_SAVED_MESSAGE]);
        }

        $topic = $conversation->topic?->load('category', 'subtopics');
        $hasCategory = $categoryId !== null || $categoryName !== null;

        // A continued topic keeps its category unless the learner picked another one.
        if ($topic === null && ! $hasCategory) {
            throw ValidationException::withMessages(['category_id' => self::CATEGORY_REQUIRED_MESSAGE]);
        }
        $existingSubtopics = $topic?->subtopics->keyBy(fn (Subtopic $subtopic): string => Str::lower($subtopic->title)) ?? collect();
        $keysNeedingMaterial = $this->keysNeedingMaterial($existingSubtopics, $subtopics);

        return DB::transaction(function () use ($conversation, $topic, $existingSubtopics, $keysNeedingMaterial, $title, $goal, $goalDeadline, $goalRecallPercentage, $subtopics, $categoryId, $categoryName, $hasCategory): array {
            $topicAttributes = [
                'title' => $title,
                'goal' => $goal,
                'goal_deadline' => $goalDeadline,
                'goal_recall_percentage' => $goalRecallPercentage,
            ];

            if ($hasCategory) {
                $topicAttributes['category_id'] = $this->resolveCategory->handle($conversation->user, $categoryId, $categoryName)->id;
            }

            if ($topic === null) {
                $topic = $conversation->user->topics()->create($topicAttributes);
            } else {
                $topic->update($topicAttributes);
            }

            // The files sent in the conversation become the topic's sources.
            $sentMaterials = Material::whereIn('message_id', $conversation->messages()->select('id'))->whereNull('topic_id');
            $readMaterialIds = (clone $sentMaterials)->where('status', MaterialStatus::Ready)->pluck('id')->all();
            $sentMaterials->update(['topic_id' => $topic->id]);

            $savedSubtopics = $this->saveSubtopics($topic, $existingSubtopics, $subtopics);
            $sourceMessageId = $conversation->messages()->where('role', MessageRole::Tutor)->latest('id')->value('id');

            if ($readMaterialIds === []) {
                foreach ($keysNeedingMaterial as $key) {
                    $this->queueMaterialGeneration->handle($savedSubtopics[$key], $sourceMessageId);
                }
            } else {
                $this->splitSourcesFirst($topic, $readMaterialIds, $keysNeedingMaterial, $savedSubtopics, $sourceMessageId);
            }

            $conversation->update([
                'topic_id' => $topic->id,
                'ended_at' => now(),
                'draft' => [
                    'title' => $title,
                    'category' => $topic->load('category')->category->name,
                    'goal' => ['description' => $goal, 'deadline' => $goalDeadline, 'recall_percentage' => $goalRecallPercentage],
                    'subtopics' => $subtopics,
                ],
            ]);

            return [
                'topic' => $topic,
                'added_subtopics' => count(array_diff_key($savedSubtopics, $existingSubtopics->all())),
            ];
        });
    }

    /**
     * Each subtopic's part of the files goes into its notes before its material is made, so the job queues the
     * material itself (specs/chat-attachments.md). The subtopics already say their cards are coming.
     *
     * @param  array<int, int>  $materialIds
     * @param  array<int, string>  $keysNeedingMaterial
     * @param  array<string, Subtopic>  $savedSubtopics  keyed by lowercase title
     */
    private function splitSourcesFirst(Topic $topic, array $materialIds, array $keysNeedingMaterial, array $savedSubtopics, ?int $sourceMessageId): void
    {
        $subtopicIdsNeedingMaterial = [];

        foreach ($keysNeedingMaterial as $key) {
            $savedSubtopics[$key]->update(['generation_status' => GenerationStatus::Generating]);
            $subtopicIdsNeedingMaterial[] = $savedSubtopics[$key]->id;
        }

        SplitSourcesOverSubtopics::dispatch($topic, $materialIds, $subtopicIdsNeedingMaterial, $sourceMessageId)->afterCommit();
    }

    /**
     * Only new subtopics and those whose notes changed need material; the rest is already covered.
     *
     * @param  Collection<string, Subtopic>  $existingSubtopics  keyed by lowercase title
     * @param  array<int, array{title: string, content: string}>  $subtopics
     * @return array<int, string> the lowercase titles of the subtopics that need material
     */
    private function keysNeedingMaterial(Collection $existingSubtopics, array $subtopics): array
    {
        $keys = [];

        foreach ($subtopics as $subtopic) {
            $key = Str::lower($subtopic['title']);
            $existing = $existingSubtopics->get($key);

            if ($existing === null || trim((string) $existing->content) !== trim($subtopic['content'])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Updates the notes of subtopics the topic already has and adds the new ones at the end. Existing
     * subtopics the learner left out stay: removing in the review only drops what isn't saved yet.
     *
     * @param  Collection<string, Subtopic>  $existingSubtopics  keyed by lowercase title
     * @param  array<int, array{title: string, content: string}>  $subtopics
     * @return array<string, Subtopic> the reviewed subtopics, keyed by lowercase title
     */
    private function saveSubtopics(Topic $topic, Collection $existingSubtopics, array $subtopics): array
    {
        $nextPosition = ($existingSubtopics->max('position') ?? 0) + 1;
        $savedSubtopics = [];

        foreach ($subtopics as $subtopic) {
            $key = Str::lower($subtopic['title']);
            $existing = $existingSubtopics->get($key);

            if ($existing !== null) {
                $existing->update(['content' => $subtopic['content']]);
                $savedSubtopics[$key] = $existing;

                continue;
            }

            $savedSubtopics[$key] = $topic->subtopics()->create([
                'title' => $subtopic['title'],
                'content' => $subtopic['content'],
                'position' => $nextPosition++,
            ]);
        }

        return $savedSubtopics;
    }
}
