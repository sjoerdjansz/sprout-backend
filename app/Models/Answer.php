<?php

namespace App\Models;

use Database\Factories\AnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's attempt at a question. Every attempt is kept.
 */
#[Fillable(['question_id', 'user_id', 'study_session_id', 'question_option_id', 'response', 'score', 'is_correct', 'feedback', 'gaps', 'answered_at'])]
class Answer extends Model
{
    /** @use HasFactory<AnswerFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StudySession, $this>
     */
    public function studySession(): BelongsTo
    {
        return $this->belongsTo(StudySession::class);
    }

    /**
     * @return BelongsTo<QuestionOption, $this>
     */
    public function questionOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_correct' => 'boolean',
            'gaps' => 'array',
            'answered_at' => 'datetime',
        ];
    }
}
