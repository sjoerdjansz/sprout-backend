<?php

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * How many days in a row the learner practised, in their timezone. A streak that reached yesterday
 * still counts until today is over, so the morning before practising doesn't show it lost.
 */
class CalculateStreak
{
    public function handle(User $learner): int
    {
        $today = CarbonImmutable::now($learner->timezone())->startOfDay();
        $activeDays = $learner->dailyActivities()
            ->whereDate('activity_date', '<=', $today->toDateString())
            ->orderByDesc('activity_date')
            ->pluck('activity_date')
            ->map(fn (mixed $date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();

        $day = in_array($today->toDateString(), $activeDays, true) ? $today : $today->subDay();
        $streak = 0;

        while (in_array($day->toDateString(), $activeDays, true)) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }
}
