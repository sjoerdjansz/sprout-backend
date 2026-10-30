<?php

namespace App\Actions;

use App\Models\Question;

/**
 * Deletes a question for good, with its options and every answer to it (the foreign keys cascade).
 */
class DeleteQuestion
{
    public function handle(Question $question): void
    {
        $question->delete();
    }
}
