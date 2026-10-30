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
 * One chat conversation. A review session has no topic, because it mixes topics. A learn session
 * ("conversation" in the API) has no topic until it is saved, and is read-only after that.
 */
#[Fillable(['user_id', 'topic_id', 'kind', 'draft', 'started_at', 'ended_at'])]
class StudySession extends Model
{
    /** @use HasFactory<StudySessionFactory> */
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
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isSaved(): bool
    {
        return $this->ended_at !== null;
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
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
