<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a subtopic from the knowledge map, and can move it to another of the learner's topics. Its
 * cards and questions move along, so its progress does too. Changed notes get new material for what
 * they now cover, as when saving from the chat; unchanged notes get nothing new.
 */
class UpdateSubtopic
{
    public function __construct(
        private EnsureSubtopicFitsTopic $ensureSubtopicFitsTopic,
        private GeneratePracticeMaterial $generatePracticeMaterial,
        private StorePracticeMaterial $storePracticeMaterial,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>, added_flashcards: int, added_questions: int}
     *
     * @throws ValidationException when the target topic already has that name or is full.
     * @throws AiRequestFailed
     */
    public function handle(Subtopic $subtopic, Topic $targetTopic, string $title, ?string $description, ?string $notes): array
    {
        $this->ensureSubtopicFitsTopic->handle($targetTopic, $title, $subtopic);

        $notesChanged = $notes !== null && trim($notes) !== trim((string) $subtopic->content);

        $material = ! $notesChanged ? null : $this->generatePracticeMaterial->handle(
            learner: $targetTopic->user,
            topic: ['title' => $targetTopic->title, 'goal' => $targetTopic->goal ?? ''],
            subtopics: [[
                'title' => $title,
                'content' => $notes,
                'existing_items' => [
                    ...$subtopic->flashcards()->pluck('front'),
                    ...$subtopic->questions()->pluck('prompt'),
                ],
            ]],
        )[0];

        $result = DB::transaction(function () use ($subtopic, $targetTopic, $title, $description, $notes, $material): array {
            $isMoving = $subtopic->topic_id !== $targetTopic->id;

            $subtopic->update([
                'topic_id' => $targetTopic->id,
                'title' => $title,
                'description' => $description,
                'content' => $notes,
                'position' => $isMoving ? ($targetTopic->subtopics()->max('position') ?? 0) + 1 : $subtopic->position,
            ]);

            if ($isMoving) {
                // Cards and questions also point at their topic, so "due today" per topic stays right.
                $subtopic->flashcards()->update(['topic_id' => $targetTopic->id]);
                $subtopic->questions()->update(['topic_id' => $targetTopic->id]);
            }

            $added = $material === null
                ? ['flashcards' => 0, 'questions' => 0]
                : $this->storePracticeMaterial->handle($subtopic, $targetTopic->user_id, $material, sourceMessageId: null);

            return [
                'subtopic' => $subtopic,
                'added_flashcards' => $added['flashcards'],
                'added_questions' => $added['questions'],
            ];
        });

        $learner = $targetTopic->user;
        $result['subtopic']->load(SummariseSubtopicProgress::relations($learner));
        $result['progress'] = $this->summariseSubtopicProgress->handle($result['subtopic'], $learner->timezone());

        return $result;
    }
}
