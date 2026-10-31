<?php

namespace App\Enums;

/**
 * The colour theme the app uses. Stored on the server so it follows the learner across devices.
 */
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
