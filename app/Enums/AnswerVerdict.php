<?php

namespace App\Enums;

use App\Models\Answer;

/**
 * How a graded answer went, as the subtopic page labels it: Correct, Almost or Not quite.
 */
enum AnswerVerdict: string
{
    case Correct = 'correct';
    case Almost = 'almost';
    case NotQuite = 'not_quite';

    /** An answer that isn't right but scores at least this got most of the way there. */
    public const ALMOST_SCORE = 50;

    /**
     * Expects a graded answer (score set).
     */
    public static function of(Answer $answer): self
    {
        if ($answer->is_correct === true) {
            return self::Correct;
        }

        return $answer->score >= self::ALMOST_SCORE ? self::Almost : self::NotQuite;
    }
}
