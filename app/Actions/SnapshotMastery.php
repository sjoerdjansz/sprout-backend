<?php

namespace App\Actions;

use App\Models\Flashcard;
use App\Models\StudySession;
use App\Models\SubtopicProgress;
use App\Models\User;

/**
 * The stored mastery of every subtopic a practice session covers, keyed by subtopic id: its subtopic, all
 * subtopics of its topic, or for a review of everything due the subtopics of the cards in its plan. A
 * subtopic that was never practised is at 0.
 */
class SnapshotMastery
{
    /**
     * @return array<int, int>
     */
    public function handle(StudySession $session, User $learner): array
    {
        $subtopicIds = match ($session->practiceScope()) {
            StudySession::SCOPE_SUBTOPIC => [$session->subtopic_id],
            StudySession::SCOPE_TOPIC => $session->topic->subtopics()->pluck('id')->all(),
            // Every subtopic of every topic would be most of the library; these are the ones it practises.
            StudySession::SCOPE_DUE => Flashcard::query()
                ->whereIn('id', $session->plan['card_ids'])
                ->whereNotNull('subtopic_id')
                ->distinct()
                ->pluck('subtopic_id')
                ->all(),
        };

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
