<?php

namespace App\Enums;

/**
 * The topics a learner can pick as curious about when signing up.
 */
enum Interest: string
{
    case Languages = 'languages';
    case Science = 'science';
    case Coding = 'coding';
    case History = 'history';
    case Maths = 'maths';
    case Other = 'other';
}
