<?php

namespace App\Enums;

/**
 * The colour behind a learner's initials: the four topic-tile tints of the design system. The frontend
 * maps each name to its tokens.
 */
enum AvatarColor: string
{
    case Sun = 'sun';
    case Mint = 'mint';
    case Peach = 'peach';
    case Sky = 'sky';
}
