<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The queue for a review of everything due today, across topics (specs/practice-session.md): the
 * learner's unpaused cards due before the end of today in their timezone, most overdue first, with
 * their schedule. A session holds at most MAX_CARDS cards, so more aren't sent.
 */
class ListDueFlashcards
{
    public const MAX_CARDS = 100;

    public function __construct(private SummariseFlashcard $summariseFlashcard) {}

    /**
     * @return array<int, array{flashcard: Flashcard, schedule: array<string, mixed>}>
     */
    public function handle(User $learner): array
    {
        $timezone = $learner->timezone();
        $endOfToday = CarbonImmutable::now($timezone)->endOfDay();

        return $learner->flashcards()
            ->whereNull('suspended_at')
            ->where('due_at', '<=', $endOfToday->utc())
            ->with('reviews')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(self::MAX_CARDS)
            ->get()
            ->map(fn (Flashcard $flashcard): array => [
                'flashcard' => $flashcard,
                'schedule' => $this->summariseFlashcard->handle($flashcard, $timezone),
            ])
            ->all();
    }
}
