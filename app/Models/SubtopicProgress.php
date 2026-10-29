<?php

namespace App\Models;

use Database\Factories\SubtopicProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived: a learner's mastery of a subtopic, recalculated from reviews and answers.
 */
#[Table('subtopic_progress')]
#[Fillable(['user_id', 'subtopic_id', 'mastery', 'last_studied_at'])]
class SubtopicProgress extends Model
{
    /** @use HasFactory<SubtopicProgressFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Subtopic, $this>
     */
    public function subtopic(): BelongsTo
    {
        return $this->belongsTo(Subtopic::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mastery' => 'integer',
            'last_studied_at' => 'datetime',
        ];
    }
}
