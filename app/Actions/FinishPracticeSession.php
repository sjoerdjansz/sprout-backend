<?php

namespace App\Actions;

use App\Models\StudySession;
use App\Models\User;

/**
 * Ends a practice session and keeps the mastery its subtopics ended on, for "before and after". A session
 * in which nothing was practised is deleted instead, so the Sessions tab never lists an empty one.
 * Finishing a finished session changes nothing.
 */
class FinishPracticeSession
{
    public function __construct(private SnapshotMastery $snapshotMastery) {}

    /**
     * Returns the finished session, or null when it was empty and is gone.
     */
    public function handle(StudySession $session, User $learner): ?StudySession
    {
        if ($session->ended_at !== null) {
            return $session;
        }

        if (SummarisePracticeSession::isEmpty($session)) {
            $session->delete();

            return null;
        }

        $session->update([
            'ended_at' => now(),
            'mastery_after' => $this->snapshotMastery->handle($session, $learner),
        ]);

        return $session;
    }
}
