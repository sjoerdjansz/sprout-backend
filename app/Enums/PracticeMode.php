<?php

namespace App\Enums;

/**
 * The ways to practise in a practice session, in the order its blocks run (specs/practice-session.md).
 */
enum PracticeMode: string
{
    case Cards = 'cards';
    case Retrieval = 'retrieval';
    case Feynman = 'feynman';
    case Open = 'open';
}
