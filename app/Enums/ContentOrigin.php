<?php

namespace App\Enums;

/**
 * Whether the learner or the AI made a flashcard or question.
 */
enum ContentOrigin: string
{
    case User = 'user';
    case Ai = 'ai';
}
