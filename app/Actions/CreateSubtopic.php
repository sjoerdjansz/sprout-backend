<?php

namespace App\Actions;

use App\Enums\SourceType;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a subtopic to a topic by hand, at the end. Its notes are the learner's own source; when it has
 * them, its flashcards and questions are made in the background (specs/background-generation.md).
 */
class CreateSubtopic
{
    public function __construct(
        private EnsureSubtopicFitsTopic $ensureSubtopicFitsTopic,
        private QueueMaterialGeneration $queueMaterialGeneration,
        private SaveSubtopicSource $saveSubtopicSource,
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
                'position' => ($topic->subtopics()->max('position') ?? 0) + 1,
            ]);

            if ($this->saveSubtopicSource->handle($subtopic, SourceType::Own, $notes)) {
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
