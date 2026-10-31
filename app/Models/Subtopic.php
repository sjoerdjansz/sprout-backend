<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Database\Factories\SubtopicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concept within a topic; the unit mastery is tracked on.
 */
#[Fillable(['topic_id', 'title', 'description', 'content', 'position', 'generation_status'])]
class Subtopic extends Model
{
    /** @use HasFactory<SubtopicFactory> */
    use HasFactory;

    /**
     * Mirrors the column's default, so a subtopic that was just created has its status without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'generation_status' => 'ready',
    ];

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return HasMany<Flashcard, $this>
     */
    public function flashcards(): HasMany
    {
        return $this->hasMany(Flashcard::class);
    }

    /**
     * @return HasMany<Question, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * @return HasMany<SubtopicProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(SubtopicProgress::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'generation_status' => GenerationStatus::class,
        ];
    }
}
