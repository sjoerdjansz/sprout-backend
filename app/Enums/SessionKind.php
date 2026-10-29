<?php

namespace App\Enums;

/**
 * A learn session studies one topic; a review session mixes due cards across topics.
 */
enum SessionKind: string
{
    case Learn = 'learn';
    case Review = 'review';
}
