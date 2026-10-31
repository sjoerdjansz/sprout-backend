<?php

namespace App\Actions;

use App\Models\FlashcardReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Reset progress" in settings: wipes the learner's learning history (reviews, answers, practice sessions,
 * mastery, streaks and XP) and makes every card new again. Their topics, subtopics, cards, questions and
 * conversations stay. Can't be undone.
 */
class ResetLearningProgress
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            FlashcardReview::whereIn('flashcard_id', $user->flashcards()->select('id'))->delete();
            $user->answers()->delete();
            $user->practiceSessions()->delete();
            $user->subtopicProgress()->delete();
            $user->dailyActivities()->delete();

            // Back to the state of a newly made card. A paused card stays paused.
            $user->flashcards()->update([
                'due_at' => now(),
                'interval_days' => 0,
                'ease_factor' => 2.5,
                'repetitions' => 0,
                'lapses' => 0,
            ]);
        });
    }
}
