<?php

namespace App\Actions;

use App\Models\StudySession;
use App\Models\SubtopicProgress;
use App\Models\User;

/**
 * The stored mastery of every subtopic a practice session covers, keyed by subtopic id: its subtopic, or
 * all subtopics of its topic. A subtopic that was never practised is at 0.
 */
class SnapshotMastery
{
    /**
     * @return array<int, int>
     */
    public function handle(StudySession $session, User $learner): array
    {
        $subtopicIds = $session->subtopic_id !== null
            ? [$session->subtopic_id]
            : $session->topic->subtopics()->pluck('id')->all();

        $masteryPerSubtopic = SubtopicProgress::query()
            ->where('user_id', $learner->id)
            ->whereIn('subtopic_id', $subtopicIds)
            ->pluck('mastery', 'subtopic_id');

        $snapshot = [];

        foreach ($subtopicIds as $subtopicId) {
            $snapshot[$subtopicId] = $masteryPerSubtopic[$subtopicId] ?? 0;
        }

        return $snapshot;
    }
}
