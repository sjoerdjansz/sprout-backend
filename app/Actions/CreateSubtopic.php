<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a subtopic to a topic by hand, at the end. When it has notes, its flashcards and questions are
 * made from them in the background (specs/background-generation.md).
 */
class CreateSubtopic
{
    public function __construct(
        private EnsureSubtopicFitsTopic $ensureSubtopicFitsTopic,
        private QueueMaterialGeneration $queueMaterialGeneration,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>}
     *
     * @throws ValidationException when the topic already has that name or is full.
     */
    public function handle(Topic $topic, string $title, ?string $description, ?string $notes): array
    {
        $this->ensureSubtopicFitsTopic->handle($topic, $title);

        $subtopic = DB::transaction(function () use ($topic, $title, $description, $notes): Subtopic {
            $subtopic = $topic->subtopics()->create([
                'title' => $title,
                'description' => $description,
                'content' => $notes,
                'position' => ($topic->subtopics()->max('position') ?? 0) + 1,
            ]);

            if ($notes !== null) {
                $this->queueMaterialGeneration->handle($subtopic);
            }

            return $subtopic;
        });

        $learner = $topic->user;
        $subtopic->load(SummariseSubtopicProgress::relations($learner));

        return [
            'subtopic' => $subtopic,
            'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
        ];
    }
}
