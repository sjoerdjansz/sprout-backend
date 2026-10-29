<?php

namespace App\Models;

use App\Enums\ReviewRating;
use Database\Factories\FlashcardReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One review of a flashcard.
 */
#[Fillable(['flashcard_id', 'study_session_id', 'rating', 'interval_days_before', 'interval_days_after', 'reviewed_at'])]
class FlashcardReview extends Model
{
    /** @use HasFactory<FlashcardReviewFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Flashcard, $this>
     */
    public function flashcard(): BelongsTo
    {
        return $this->belongsTo(Flashcard::class);
    }

    /**
     * @return BelongsTo<StudySession, $this>
     */
    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating' => ReviewRating::class,
            'interval_days_before' => 'integer',
            'interval_days_after' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }
}
