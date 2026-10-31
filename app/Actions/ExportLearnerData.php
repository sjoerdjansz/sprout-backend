<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Loads everything a learner has in Sprout, for the "Export your library" download (specs/settings.md).
 * LearnerExportResource shapes it. Never includes anything secret, such as old AI keys.
 */
class ExportLearnerData
{
    public function handle(User $user): User
    {
        return $user->load([
            'settings',
            'categories' => fn (HasMany $query) => $query->orderBy('id'),
            'topics' => fn (HasMany $query) => $query->orderBy('id'),
            'topics.category',
            'topics.subtopics' => fn (HasMany $query) => $query->orderBy('position'),
            'topics.subtopics.progress',
            'topics.subtopics.sources',
            'topics.flashcards' => fn (HasMany $query) => $query->orderBy('id'),
            'topics.flashcards.reviews' => fn (HasMany $query) => $query->orderBy('reviewed_at'),
            'topics.questions' => fn (HasMany $query) => $query->orderBy('id'),
            'topics.questions.options' => fn (HasMany $query) => $query->orderBy('position'),
            'topics.questions.answers' => fn (HasMany $query) => $query->orderBy('answered_at'),
            'studySessions' => fn (HasMany $query) => $query->orderBy('started_at'),
            'studySessions.messages' => fn (HasMany $query) => $query->orderBy('id'),
            'materials' => fn (HasMany $query) => $query->orderBy('id'),
            'dailyActivities' => fn (HasMany $query) => $query->orderBy('activity_date'),
        ]);
    }
}
