<?php

namespace App\Actions;

use App\Models\User;

/**
 * Counts practice towards today's activity (in the learner's timezone): the cards reviewed, the
 * questions answered and the XP earned. Any practice keeps the streak going. XP rewards effort, not
 * being right, because a wrong answer is information, not failure (specs/practice-session.md).
 */
class RecordPracticeActivity
{
    public const XP_PER_CARD = 1;

    public const XP_PER_ANSWER = 2;

    public function handle(User $learner, int $cardsReviewed = 0, int $questionsAnswered = 0): void
    {
        $today = now($learner->timezone())->toDateString();

        $activity = $learner->dailyActivities()->whereDate('activity_date', $today)->first()
            ?? $learner->dailyActivities()->create(['activity_date' => $today]);

        $activity->increment('cards_reviewed', $cardsReviewed);
        $activity->increment('questions_answered', $questionsAnswered);
        $activity->increment('xp_earned', $cardsReviewed * self::XP_PER_CARD + $questionsAnswered * self::XP_PER_ANSWER);
    }
}
