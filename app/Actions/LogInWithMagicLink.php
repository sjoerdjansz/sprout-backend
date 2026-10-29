<?php

namespace App\Actions;

use App\Models\MagicLinkToken;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class LogInWithMagicLink
{
    public const INVALID_LINK_MESSAGE = 'This link has expired or was already used. Request a new one.';

    /**
     * @throws ValidationException when the link is unknown, expired or already used.
     */
    public function handle(string $plainToken): NewAccessToken
    {
        $magicLinkToken = MagicLinkToken::usable()
            ->where('token_hash', MagicLinkToken::hashToken($plainToken))
            ->first();

        if ($magicLinkToken === null) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
        }

        // Claim the link with a conditional update, so two simultaneous clicks can't both sign in.
        $wasClaimed = MagicLinkToken::whereKey($magicLinkToken->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;

        if (! $wasClaimed) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
        }

        $user = $magicLinkToken->user;

        // Opening the link proves the user owns the email address.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user->createToken('magic-link');
    }
}
