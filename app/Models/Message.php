<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Enums\Technique;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat turn, by the learner or the tutor.
 */
#[Fillable(['study_session_id', 'role', 'content', 'technique', 'model', 'input_tokens', 'output_tokens'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<StudySession, $this>
     */
    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class);
    }

    /**
     * The files the learner attached to this message (specs/chat-attachments.md).
     *
     * @return HasMany<Material, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'technique' => Technique::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }
}
