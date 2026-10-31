<?php

namespace App\Enums;

/**
 * Where a learner wants their reminders. Stored only: nothing sends reminders yet.
 */
enum ReminderChannel: string
{
    case Push = 'push';
    case Email = 'email';
    case Both = 'both';
}
