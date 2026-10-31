<?php

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * How many days in a row the learner practised, in their timezone: the streak now and the longest
 * one ever. A streak that reached yesterday still counts until today is over, so the morning before
 * practising doesn't show it lost.
 */
class CalculateStreak
{
    /**
     * @return array{current_days: int, best_days: int}
     */
    public function handle(User $learner): array
    {
        $today = CarbonImmutable::now($learner->timezone())->startOfDay();
        // One row per active day, so reading them all stays cheap.
        $activeDays = $learner->dailyActivities()
            ->whereDate('activity_date', '<=', $today->toDateString())
            ->orderBy('activity_date')
            ->pluck('activity_date')
            ->map(fn (mixed $date): string => CarbonImmutable::parse($date)->toDateString())
            ->all();

        return [
            'current_days' => $this->currentStreak($activeDays, $today),
            'best_days' => $this->longestRun($activeDays),
        ];
    }

    /**
     * @param  list<string>  $activeDays
     */
    private function currentStreak(array $activeDays, CarbonImmutable $today): int
    {
        $day = in_array($today->toDateString(), $activeDays, true) ? $today : $today->subDay();
        $streak = 0;

        while (in_array($day->toDateString(), $activeDays, true)) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    /**
     * @param  list<string>  $activeDays  oldest first
     */
    private function longestRun(array $activeDays): int
    {
        $longest = 0;
        $run = 0;
        $previousDay = null;

        foreach ($activeDays as $date) {
            $day = CarbonImmutable::parse($date);
            $run = $previousDay !== null && $previousDay->addDay()->isSameDay($day) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previousDay = $day;
        }

        return $longest;
    }
}
