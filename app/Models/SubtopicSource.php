<?php

namespace App\Models;

use App\Enums\SourceType;
use Database\Factories\SubtopicSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One source a subtopic's flashcards and questions are made from: the tutor's notes, the learner's own notes,
 * or the part of an uploaded file (specs/subtopic-sources.md).
 */
#[Fillable(['subtopic_id', 'type', 'material_id', 'content'])]
class SubtopicSource extends Model
{
    /** @use HasFactory<SubtopicSourceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Subtopic, $this>
     */
    public function subtopic(): BelongsTo
    {
        return $this->belongsTo(Subtopic::class);
    }

    /**
     * The uploaded file a file source is a part of; null for the other types.
     *
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * How the source is introduced to the AI and named in the app.
     */
    public function label(): string
    {
        return match ($this->type) {
            SourceType::Tutor => 'From the chat',
            SourceType::Own => 'Your notes',
            SourceType::File => "From {$this->material->original_filename}",
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
        ];
    }
}
