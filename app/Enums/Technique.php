<?php

namespace App\Enums;

/**
 * The learning techniques the tutor applies, shown as a badge on its messages.
 */
enum Technique: string
{
    case RetrievalPractice = 'retrieval_practice';
    case SpacedRepetition = 'spaced_repetition';
    case TestingEffect = 'testing_effect';
    case Feynman = 'feynman';
    case Interleaving = 'interleaving';
}
