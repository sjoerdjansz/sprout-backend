<?php

namespace App\Enums;

/**
 * Who wrote a chat message.
 */
enum MessageRole: string
{
    case User = 'user';
    case Tutor = 'tutor';
}
