<?php

namespace App\Actions;

use App\Models\Subtopic;

/**
 * Deletes a subtopic for good. Its flashcards with their reviews, its questions with their answers,
 * and the learner's progress on it go with it (the foreign keys cascade).
 */
class DeleteSubtopic
{
    public function handle(Subtopic $subtopic): void
    {
        $subtopic->delete();
    }
}
