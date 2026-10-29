<?php

namespace App\Models;

use App\Enums\ContentOrigin;
use Database\Factories\FlashcardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flashcard with its SM-2 scheduling state. The review history is in flashcard_reviews.
 */
#[Fillable(['user_id', 'topic_id', 'subtopic_id', 'front', 'back', 'origin', 'source_message_id', 'due_at', 'interval_days', 'ease_factor', 'repetitions', 'lapses', 'suspended_at'])]
class Flashcard extends Model
{
    /** @use HasFactory<FlashcardFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Topic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * @return BelongsTo<Subtopic, $this>
     */
    public function subtopic(): BelongsTo
    {
        return $this->belongsTo(Subtopic::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /**
     * @return HasMany<FlashcardReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(FlashcardReview::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => ContentOrigin::class,
            'due_at' => 'datetime',
            'interval_days' => 'integer',
            'ease_factor' => 'float',
            'repetitions' => 'integer',
            'lapses' => 'integer',
            'suspended_at' => 'datetime',
        ];
    }
}
