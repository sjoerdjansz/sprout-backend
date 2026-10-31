<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\SourceType;
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
     * What its flashcards and questions are made from (specs/subtopic-sources.md): the tutor's notes, then the
     * learner's own notes, then the files in the order they were uploaded.
     *
     * @return HasMany<SubtopicSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(SubtopicSource::class)
            ->orderByRaw("case type when 'tutor' then 1 when 'own' then 2 else 3 end")
            ->orderBy('material_id');
    }

    /**
     * All sources as one Markdown text, each under a heading that says where it comes from. This is what
     * the AI reads about the subtopic; empty when it has no sources.
     */
    public function sourceText(): string
    {
        return $this->sources
            ->map(fn (SubtopicSource $source): string => "## {$source->label()}\n\n".trim($source->content))
            ->implode("\n\n");
    }

    /**
     * The content of the source of one type; for tutor and own notes, of which a subtopic has at most one.
     */
    public function sourceContent(SourceType $type): ?string
    {
        return $this->sources->firstWhere('type', $type)?->content;
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
