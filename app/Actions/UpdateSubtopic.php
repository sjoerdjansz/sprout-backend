<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a subtopic from the knowledge map, and can move it to another of the learner's topics. Its
 * cards and questions move along, so its progress does too. Changed notes get new material for what
 * they now cover, made in the background as when saving from the chat; unchanged notes get nothing new.
 */
class UpdateSubtopic
{
    public function __construct(
        private EnsureSubtopicFitsTopic $ensureSubtopicFitsTopic,
        private QueueMaterialGeneration $queueMaterialGeneration,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>}
     *
     * @throws ValidationException when the target topic already has that name or is full.
     */
    public function handle(Subtopic $subtopic, Topic $targetTopic, string $title, ?string $description, ?string $notes): array
    {
        $this->ensureSubtopicFitsTopic->handle($targetTopic, $title, $subtopic);

        $notesChanged = $notes !== null && trim($notes) !== trim((string) $subtopic->content);

        DB::transaction(function () use ($subtopic, $targetTopic, $title, $description, $notes, $notesChanged): void {
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

            if ($notesChanged) {
                $this->queueMaterialGeneration->handle($subtopic);
            }
        });

        $learner = $targetTopic->user;
        $subtopic->load(SummariseSubtopicProgress::relations($learner));

        return [
            'subtopic' => $subtopic,
            'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
        ];
    }
}
