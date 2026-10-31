<?php

namespace App\Actions;

use App\Enums\ReviewRating;
use App\Models\Flashcard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Where a flashcard's SM-2 schedule goes after a rating, in the Anki-like variant from
 * specs/practice-session.md. Stores nothing, so the rating bar can show the outcome of every rating
 * before the learner picks one (SummariseFlashcard).
 */
class ScheduleFlashcard
{
    /** "Again" brings the card back within the same session. */
    public const AGAIN_MINUTES = 10;

    private const MINIMUM_EASE = 1.3;

    private const HARD_MULTIPLIER = 1.2;

    private const EASY_BONUS = 1.3;

    /**
     * @return array{due_at: CarbonImmutable, interval_days: int, ease_factor: float, repetitions: int, lapses: int}
     */
    public function handle(Flashcard $flashcard, ReviewRating $rating, CarbonInterface $now): array
    {
        $now = CarbonImmutable::instance($now);
        $ease = $flashcard->ease_factor;
        $previousInterval = $flashcard->interval_days;
        // No streak of remembered reviews: a new card, or one that was forgotten and is being relearned.
        $isLearning = $flashcard->repetitions === 0;

        if ($rating === ReviewRating::Again) {
            return [
                'due_at' => $now->addMinutes(self::AGAIN_MINUTES),
                'interval_days' => 0,
                'ease_factor' => $this->limitEase($ease - 0.20),
                'repetitions' => 0,
                'lapses' => $flashcard->lapses + 1,
            ];
        }

        // Good always grows the interval by at least a day, so "1 day, then 3 days" with the starting ease.
        $goodInterval = $isLearning ? 1 : max($previousInterval + 1, (int) round($previousInterval * $ease));

        [$interval, $newEase] = match ($rating) {
            ReviewRating::Hard => [
                $isLearning ? 1 : max(1, (int) round($previousInterval * self::HARD_MULTIPLIER)),
                $this->limitEase($ease - 0.15),
            ],
            ReviewRating::Good => [$goodInterval, $ease],
            ReviewRating::Easy => [
                $isLearning ? 4 : max($goodInterval + 1, (int) round($previousInterval * $ease * self::EASY_BONUS)),
                round($ease + 0.15, 2),
            ],
        };

        return [
            'due_at' => $now->addDays($interval),
            'interval_days' => $interval,
            'ease_factor' => $newEase,
            'repetitions' => $flashcard->repetitions + 1,
            'lapses' => $flashcard->lapses,
        ];
    }

    private function limitEase(float $ease): float
    {
        return round(max(self::MINIMUM_EASE, $ease), 2);
    }
}
