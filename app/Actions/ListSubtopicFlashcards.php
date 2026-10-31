<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\Subtopic;
use App\Models\User;

/**
 * Every flashcard of a subtopic, oldest first, with its schedule. Paused cards are included: the
 * subtopic page lists and filters them.
 */
class ListSubtopicFlashcards
{
    public function __construct(private SummariseFlashcard $summariseFlashcard) {}

    /**
     * @return array<int, array{flashcard: Flashcard, schedule: array<string, mixed>}>
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        $timezone = $learner->timezone();

        return $subtopic->flashcards()
            ->with('reviews')
            ->orderBy('id')
            ->get()
            ->map(fn (Flashcard $flashcard): array => [
                'flashcard' => $flashcard,
                'schedule' => $this->summariseFlashcard->handle($flashcard, $timezone),
            ])
            ->all();
    }
}
