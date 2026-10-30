<?php

namespace App\Enums;

/**
 * How well the learner remembered a flashcard; drives SM-2 scheduling.
 */
enum ReviewRating: string
{
    case Again = 'again';
    case Hard = 'hard';
    case Good = 'good';
    case Easy = 'easy';

    /**
     * The rating as a 0–100 score, so card reviews can be compared with graded answers. Good sits on
     * the 85% that counts as holding: remembering without trouble is what holding means.
     */
    public function score(): int
    {
        return match ($this) {
            self::Again => 0,
            self::Hard => 60,
            self::Good => 85,
            self::Easy => 100,
        };
    }
}
