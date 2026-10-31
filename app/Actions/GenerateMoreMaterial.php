<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Generate from sources" on the subtopic page: more flashcards and questions from all of the subtopic's
 * sources (specs/subtopic-sources.md), made in the background (specs/background-generation.md). The job
 * passes the existing cards and questions along, so the AI adds what they don't cover yet. This is
 * also how a subtopic whose generation failed is tried again.
 */
class GenerateMoreMaterial
{
    public const STILL_GENERATING_MESSAGE = "Sprout is still making cards for this subtopic. They'll show up in a moment.";

    public function __construct(
        private QueueMaterialGeneration $queueMaterialGeneration,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>}
     *
     * @throws ValidationException when the subtopic has no notes to generate from, or is still generating.
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        if (! $subtopic->sources()->exists()) {
            throw ValidationException::withMessages([
                'notes' => 'Add notes to this subtopic first, so we can make cards from them.',
            ]);
        }

        // A double click shouldn't make everything twice; the running job already covers the notes.
        if ($subtopic->generation_status === GenerationStatus::Generating) {
            throw ValidationException::withMessages(['subtopic' => self::STILL_GENERATING_MESSAGE]);
        }

        $this->queueMaterialGeneration->handle($subtopic);

        $subtopic->load(SummariseSubtopicProgress::relations($learner));

        return [
            'subtopic' => $subtopic,
            'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
        ];
    }
}
