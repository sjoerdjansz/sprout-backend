<?php

namespace App\Actions;

use App\Models\Flashcard;

/**
 * Deletes a flashcard for good, with its review history (the foreign key cascades). The facets
 * that came from those reviews change with it; the stored mastery doesn't.
 */
class DeleteFlashcard
{
    public function handle(Flashcard $flashcard): void
    {
        $flashcard->delete();
    }
}
