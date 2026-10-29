<?php

namespace App\Enums;

/**
 * Multiple choice and open questions test recall; Feynman asks the learner to explain a concept.
 */
enum QuestionType: string
{
    case MultipleChoice = 'multiple_choice';
    case Open = 'open';
    case Feynman = 'feynman';
}
