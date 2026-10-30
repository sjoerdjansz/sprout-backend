<?php

namespace App\Actions;

use App\Enums\ReviewRating;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A flashcard's schedule as the subtopic page shows it: whether it is due, how it was last rated and
 * how likely the learner is to remember it right now. Expects the card's reviews to be loaded.
 */
class SummariseFlashcard
{
    /** SM-2 schedules a card for the moment recall has dropped to about 90%. */
    private const RECALL_AT_DUE = 0.9;

    /**
     * @return array{paused: bool, is_due: bool, due_on: string, last_rating: ?ReviewRating, last_reviewed_at: ?CarbonInterface, recall: ?int}
     */
    public function handle(Flashcard $flashcard, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $isPaused = $flashcard->suspended_at !== null;
        $lastReview = $flashcard->reviews->sortByDesc(fn (FlashcardReview $review): int => $review->reviewed_at->getTimestamp())->first();
        $recall = self::expectedRecall($flashcard, $now);

        // An overdue card is due today, as in the knowledge map's "next review".
        $dueOn = $flashcard->due_at->copy()->setTimezone($timezone)->startOfDay()->max($now->startOfDay());

        return [
            'paused' => $isPaused,
            'is_due' => ! $isPaused && $flashcard->due_at->lte($now->endOfDay()),
            'due_on' => $dueOn->toDateString(),
            'last_rating' => $lastReview?->rating,
            'last_reviewed_at' => $lastReview?->reviewed_at,
            'recall' => $recall === null ? null : (int) round($recall * 100),
        ];
    }

    /**
     * The chance of remembering the card right now, from 0 to 1, or null when it was never reviewed.
     * Recall decays from 100% at the last review to about 90% when the card falls due, and on from there.
     */
    public static function expectedRecall(Flashcard $flashcard, CarbonInterface $now): ?float
    {
        if ($flashcard->reviews->isEmpty()) {
            return null;
        }

        $lastReviewedAt = $flashcard->reviews->max('reviewed_at');
        $daysSinceReview = max(0, $lastReviewedAt->diffInSeconds($now)) / 86400;

        return self::RECALL_AT_DUE ** ($daysSinceReview / max(1, $flashcard->interval_days));
    }
}
