<?php

namespace App\Enums;

/**
 * Where an upload is in turning into text the tutor can use.
 */
enum MaterialStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
