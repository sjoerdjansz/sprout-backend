<?php

namespace App\Actions;

use App\Enums\ReviewRating;
use App\Models\Flashcard;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves how well the learner remembered a card: the card gets its next SM-2 review moment, the
 * review is kept in its history, it counts towards today's activity, and the subtopic's mastery is
 * worked out again.
 */
class ReviewFlashcard
{
    public function __construct(
        private ScheduleFlashcard $scheduleFlashcard,
        private SummariseFlashcard $summariseFlashcard,
        private RecordPracticeActivity $recordPracticeActivity,
        private RecalculateSubtopicMastery $recalculateSubtopicMastery,
    ) {}

    /**
     * @return array{flashcard: Flashcard, schedule: array<string, mixed>, subtopic_progress: ?array<string, mixed>}
     */
    public function handle(Flashcard $flashcard, User $learner, ReviewRating $rating, ?StudySession $practiceSession = null): array
    {
        return DB::transaction(function () use ($flashcard, $learner, $rating, $practiceSession): array {
            $now = now();
            $intervalBefore = $flashcard->interval_days;

            $flashcard->update($this->scheduleFlashcard->handle($flashcard, $rating, $now));
            $flashcard->reviews()->create([
                'study_session_id' => $practiceSession?->id,
                'rating' => $rating,
                'interval_days_before' => $intervalBefore,
                'interval_days_after' => $flashcard->interval_days,
                'reviewed_at' => $now,
            ]);

            $this->recordPracticeActivity->handle($learner, cardsReviewed: 1);

            // A card without a subtopic has no mastery to update.
            $subtopicProgress = $flashcard->subtopic === null
                ? null
                : $this->recalculateSubtopicMastery->handle($flashcard->subtopic, $learner);

            return [
                'flashcard' => $flashcard,
                'schedule' => $this->summariseFlashcard->handle($flashcard->load('reviews'), $learner->timezone()),
                'subtopic_progress' => $subtopicProgress,
            ];
        });
    }
}
