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
}
