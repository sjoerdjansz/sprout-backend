<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\User;

/**
 * Rewrites a flashcard's front and back. Its schedule and review history stay: fixing a typo or
 * sharpening a card shouldn't throw away what the learner already remembers.
 */
class EditFlashcard
{
    public function __construct(private SummariseFlashcard $summariseFlashcard) {}

    /**
     * @return array{flashcard: Flashcard, schedule: array<string, mixed>}
     */
    public function handle(Flashcard $flashcard, User $learner, string $front, string $back): array
    {
        $flashcard->update(['front' => $front, 'back' => $back]);

        return [
            'flashcard' => $flashcard,
            'schedule' => $this->summariseFlashcard->handle($flashcard->load('reviews'), $learner->timezone()),
        ];
    }
}
