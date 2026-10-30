<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saves the reviewed draft of a conversation as a topic in the knowledge map, generates flashcards and
 * questions for what is new, and ends the conversation. Continuing a topic adds to the same topic.
 */
class SaveConversationToKnowledgeMap
{
    public function __construct(
        private GeneratePracticeMaterial $generatePracticeMaterial,
        private StorePracticeMaterial $storePracticeMaterial,
    ) {}

    /**
     * @param  array<int, array{title: string, content: string}>  $subtopics  as reviewed by the learner
     * @return array{topic: Topic, added_subtopics: int, added_flashcards: int, added_questions: int}
     *
     * @throws ValidationException when the conversation is already saved.
     * @throws AiRequestFailed when generating fails; nothing is saved then.
     */
    public function handle(
        StudySession $conversation,
        string $title,
        string $goal,
        string $goalDeadline,
        int $goalRecallPercentage,
        array $subtopics,
    ): array {
        if ($conversation->isSaved()) {
            throw ValidationException::withMessages(['conversation' => SendMessageToTutor::ALREADY_SAVED_MESSAGE]);
        }

        $topic = $conversation->topic?->load('subtopics.flashcards', 'subtopics.questions');
        $existingSubtopics = $topic?->subtopics->keyBy(fn (Subtopic $subtopic): string => Str::lower($subtopic->title)) ?? collect();

        // Only new subtopics and those whose notes changed need material; the rest is already covered.
        $subtopicsNeedingMaterial = [];

        foreach ($subtopics as $subtopic) {
            $existing = $existingSubtopics->get(Str::lower($subtopic['title']));

            if ($existing !== null && trim((string) $existing->content) === trim($subtopic['content'])) {
                continue;
            }

            $subtopicsNeedingMaterial[] = [
                'title' => $subtopic['title'],
                'content' => $subtopic['content'],
                'existing_items' => $existing === null ? [] : [
                    ...$existing->flashcards->pluck('front'),
                    ...$existing->questions->pluck('prompt'),
                ],
            ];
        }

        $material = $subtopicsNeedingMaterial === [] ? [] : $this->generatePracticeMaterial->handle(
            learner: $conversation->user,
            topic: ['title' => $title, 'goal' => $goal],
            subtopics: $subtopicsNeedingMaterial,
        );

        return DB::transaction(function () use ($conversation, $topic, $existingSubtopics, $title, $goal, $goalDeadline, $goalRecallPercentage, $subtopics, $subtopicsNeedingMaterial, $material): array {
            $goalAttributes = [
                'title' => $title,
                'goal' => $goal,
                'goal_deadline' => $goalDeadline,
                'goal_recall_percentage' => $goalRecallPercentage,
            ];

            if ($topic === null) {
                $topic = $conversation->user->topics()->create($goalAttributes);
            } else {
                $topic->update($goalAttributes);
            }

            $savedSubtopics = $this->saveSubtopics($topic, $existingSubtopics, $subtopics);
            $sourceMessageId = $conversation->messages()->where('role', MessageRole::Tutor)->latest('id')->value('id');
            $addedFlashcards = 0;
            $addedQuestions = 0;

            foreach ($subtopicsNeedingMaterial as $index => $subtopicNeedingMaterial) {
                $added = $this->storePracticeMaterial->handle(
                    subtopic: $savedSubtopics[Str::lower($subtopicNeedingMaterial['title'])],
                    learnerId: $conversation->user_id,
                    material: $material[$index],
                    sourceMessageId: $sourceMessageId,
                );
                $addedFlashcards += $added['flashcards'];
                $addedQuestions += $added['questions'];
            }

            $conversation->update([
                'topic_id' => $topic->id,
                'ended_at' => now(),
                'draft' => [
                    'title' => $title,
                    'goal' => ['description' => $goal, 'deadline' => $goalDeadline, 'recall_percentage' => $goalRecallPercentage],
                    'subtopics' => $subtopics,
                ],
            ]);

            return [
                'topic' => $topic,
                'added_subtopics' => count(array_diff_key($savedSubtopics, $existingSubtopics->all())),
                'added_flashcards' => $addedFlashcards,
                'added_questions' => $addedQuestions,
            ];
        });
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
