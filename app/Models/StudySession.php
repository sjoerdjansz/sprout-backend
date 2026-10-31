<?php

namespace App\Models;

use App\Enums\SessionKind;
use Database\Factories\StudySessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A learn session ("conversation" in the API) explores a topic with the tutor: it has no topic until it is
 * saved, and is read-only after that. A review session is a practice session on a topic, one of its
 * subtopics or everything due today across topics (no topic): its card reviews, answers and messages
 * point to it.
 */
#[Fillable(['user_id', 'topic_id', 'subtopic_id', 'kind', 'draft', 'plan', 'mastery_before', 'mastery_after', 'started_at', 'ended_at'])]
class StudySession extends Model
{
    /** @use HasFactory<StudySessionFactory> */
    use HasFactory;

    /** What a practice session covers, see practiceScope(). */
    public const SCOPE_TOPIC = 'topic';

    public const SCOPE_SUBTOPIC = 'subtopic';

    public const SCOPE_DUE = 'due';

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
     * @return HasMany<FlashcardReview, $this>
     */
    public function flashcardReviews(): HasMany
    {
        return $this->hasMany(FlashcardReview::class);
    }

    /**
     * @return HasMany<Answer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The first few messages, so a list of conversations can show how each one started.
     *
     * @return HasMany<Message, $this>
     */
    public function previewMessages(): HasMany
    {
        return $this->messages()->orderBy('id')->limit(3);
    }

    public function isSaved(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * What a practice session covers: one subtopic, a whole topic, or everything due today across topics
     * (a review session without a topic).
     */
    public function practiceScope(): string
    {
        if ($this->subtopic_id !== null) {
            return self::SCOPE_SUBTOPIC;
        }

        return $this->topic_id !== null ? self::SCOPE_TOPIC : self::SCOPE_DUE;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SessionKind::class,
            'draft' => 'array',
            'plan' => 'array',
            'mastery_before' => 'array',
            'mastery_after' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
