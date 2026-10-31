<?php

namespace App\Enums;

/**
 * A learn session is a conversation exploring a topic with the tutor; a review session is a practice
 * session (specs/practice-session.md) on a topic or one of its subtopics.
 */
enum SessionKind: string
{
    case Learn = 'learn';
    case Review = 'review';
}
