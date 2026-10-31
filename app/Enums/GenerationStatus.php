<?php

namespace App\Enums;

/**
 * Whether a subtopic's flashcards and questions are being made in the background (specs/background-generation.md).
 */
enum GenerationStatus: string
{
    // Nothing is being made: the material is there, or there are no notes to make it from.
    case Ready = 'ready';
    case Generating = 'generating';
    // Every try failed; "Generate from sources" tries again.
    case Failed = 'failed';
}
