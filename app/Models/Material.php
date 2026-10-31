<?php

namespace App\Models;

use App\Enums\MaterialStatus;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file the learner uploaded, read into text (specs/chat-attachments.md). It is sent with a chat
 * message and becomes a source of the topic when the conversation is saved.
 */
#[Fillable(['user_id', 'topic_id', 'message_id', 'original_filename', 'storage_path', 'mime_type', 'size_bytes', 'extracted_text', 'status', 'error'])]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory, Prunable;

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
     * The learner's message it was sent with; null until it is sent.
     *
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isSent(): bool
    {
        return $this->message_id !== null || $this->topic_id !== null;
    }

    /**
     * Files that were uploaded but never sent (or whose unsaved conversation was deleted) only take up space.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::whereNull('message_id')
            ->whereNull('topic_id')
            ->where('created_at', '<', now()->subDay());
    }

    /**
     * Pruning deletes the row, so the file goes with it.
     */
    protected function pruning(): void
    {
        Storage::disk('local')->delete($this->storage_path);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => MaterialStatus::class,
        ];
    }
}
