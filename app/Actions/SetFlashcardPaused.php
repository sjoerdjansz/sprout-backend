<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\User;

/**
 * Pauses or resumes a flashcard. A paused card is never due and doesn't count for "due today",
 * recall or slipping. Resuming keeps its due date, so a card that fell due while paused is due today.
 */
class SetFlashcardPaused
{
    public function __construct(private SummariseFlashcard $summariseFlashcard) {}

    /**
     * @return array{flashcard: Flashcard, schedule: array<string, mixed>}
     */
    public function handle(Flashcard $flashcard, User $learner, bool $paused): array
    {
        if ($paused && $flashcard->suspended_at === null) {
            $flashcard->update(['suspended_at' => now()]);
        }

        if (! $paused && $flashcard->suspended_at !== null) {
            $flashcard->update(['suspended_at' => null]);
        }

        return [
            'flashcard' => $flashcard,
            'schedule' => $this->summariseFlashcard->handle($flashcard->load('reviews'), $learner->timezone()),
        ];
    }
}
