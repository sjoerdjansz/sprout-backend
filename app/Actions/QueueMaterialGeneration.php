<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Subtopic;

/**
 * Marks a subtopic as generating and queues the job that makes its flashcards and questions
 * (specs/background-generation.md). The job starts once the surrounding transaction is committed,
 * so it never runs for a subtopic that wasn't saved.
 */
class QueueMaterialGeneration
{
    /**
     * @param  int|null  $sourceMessageId  the tutor message the notes came from, when saved from a conversation
     */
    public function handle(Subtopic $subtopic, ?int $sourceMessageId = null): void
    {
        $subtopic->update(['generation_status' => GenerationStatus::Generating]);

        GenerateSubtopicMaterial::dispatch($subtopic, $sourceMessageId)->afterCommit();
    }
}
