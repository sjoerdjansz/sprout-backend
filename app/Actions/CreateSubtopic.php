<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a subtopic to a topic by hand, at the end. When it has notes, flashcards and questions are
 * generated from them first, so a failed generation saves nothing.
 */
class CreateSubtopic
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
     * @throws ValidationException when the topic already has that name or is full.
     * @throws AiRequestFailed
     */
    public function handle(Topic $topic, string $title, ?string $description, ?string $notes): array
    {
        $this->ensureSubtopicFitsTopic->handle($topic, $title);

        $material = $notes === null ? null : $this->generatePracticeMaterial->handle(
            learner: $topic->user,
            topic: ['title' => $topic->title, 'goal' => $topic->goal ?? ''],
            subtopics: [['title' => $title, 'content' => $notes, 'existing_items' => []]],
        )[0];

        $result = DB::transaction(function () use ($topic, $title, $description, $notes, $material): array {
            $subtopic = $topic->subtopics()->create([
                'title' => $title,
                'description' => $description,
                'content' => $notes,
                'position' => ($topic->subtopics()->max('position') ?? 0) + 1,
            ]);

            $added = $material === null
                ? ['flashcards' => 0, 'questions' => 0]
                : $this->storePracticeMaterial->handle($subtopic, $topic->user_id, $material, sourceMessageId: null);

            return [
                'subtopic' => $subtopic,
                'added_flashcards' => $added['flashcards'],
                'added_questions' => $added['questions'],
            ];
        });

        $learner = $topic->user;
        $result['subtopic']->load(SummariseSubtopicProgress::relations($learner));
        $result['progress'] = $this->summariseSubtopicProgress->handle($result['subtopic'], $learner->timezone());

        return $result;
    }
}
