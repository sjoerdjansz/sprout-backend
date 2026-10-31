<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Generate from sources" on the subtopic page: more flashcards and questions from the subtopic's
 * notes, its only source for now. The existing cards and questions are passed along, so the AI adds
 * what they don't cover yet instead of repeating them.
 */
class GenerateMoreMaterial
{
    public function __construct(
        private GeneratePracticeMaterial $generatePracticeMaterial,
        private StorePracticeMaterial $storePracticeMaterial,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>, added_flashcards: int, added_questions: int}
     *
     * @throws ValidationException when the subtopic has no notes to generate from.
     * @throws AiRequestFailed
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        if (trim((string) $subtopic->content) === '') {
            throw ValidationException::withMessages([
                'notes' => 'Add notes to this subtopic first, so we can make cards from them.',
            ]);
        }

        $topic = $subtopic->topic;

        $material = $this->generatePracticeMaterial->handle(
            learner: $learner,
            topic: ['title' => $topic->title, 'goal' => $topic->goal ?? ''],
            subtopics: [[
                'title' => $subtopic->title,
                'content' => $subtopic->content,
                'existing_items' => [
                    ...$subtopic->flashcards()->pluck('front'),
                    ...$subtopic->questions()->pluck('prompt'),
                ],
            ]],
        )[0];

        $added = DB::transaction(
            fn (): array => $this->storePracticeMaterial->handle($subtopic, $learner->id, $material, sourceMessageId: null),
        );

        $subtopic->load(SummariseSubtopicProgress::relations($learner));

        return [
            'subtopic' => $subtopic,
            'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
            'added_flashcards' => $added['flashcards'],
            'added_questions' => $added['questions'],
        ];
    }
}
