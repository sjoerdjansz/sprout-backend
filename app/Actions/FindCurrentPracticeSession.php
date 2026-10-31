<?php

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The practice session a reload can pick up again: one that isn't finished and started today, in the
 * learner's timezone. Sessions can be resumed until the end of the day they started on.
 */
class FindCurrentPracticeSession
{
    public function __construct(private SummarisePracticeSession $summarisePracticeSession) {}

    /**
     * Returns the session with its summary (see SummarisePracticeSession), or null when there is none.
     *
     * @return ?array<string, mixed>
     */
    public function handle(User $learner): ?array
    {
        $startOfToday = CarbonImmutable::now($learner->timezone())->startOfDay();

        $session = $learner->practiceSessions()
            ->whereNull('ended_at')
            ->where('started_at', '>=', $startOfToday->utc())
            ->latest('started_at')
            ->first();

        return $session === null ? null : $this->summarisePracticeSession->handle($session, $learner);
    }
}
