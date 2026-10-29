<?php

namespace App\Models;

use Database\Factories\DailyActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived: what a learner did on one day. Streaks are consecutive activity dates.
 */
#[Fillable(['user_id', 'activity_date', 'xp_earned', 'cards_reviewed', 'questions_answered'])]
class DailyActivity extends Model
{
    /** @use HasFactory<DailyActivityFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'xp_earned' => 'integer',
            'cards_reviewed' => 'integer',
            'questions_answered' => 'integer',
        ];
    }
}
