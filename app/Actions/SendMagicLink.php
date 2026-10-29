<?php

namespace App\Actions;

use App\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendMagicLink
{
    public function handle(User $user): void
    {
        $plainToken = Str::random(64);

        $user->magicLinkTokens()->create([
            'token_hash' => MagicLinkToken::hashToken($plainToken),
            'expires_at' => now()->addMinutes(MagicLinkToken::LIFETIME_IN_MINUTES),
        ]);

        // The link opens the frontend, which exchanges the token for an API token.
        $magicLinkUrl = config('app.frontend_url').'/auth/verify?'.http_build_query(['token' => $plainToken]);

        Mail::to($user)->send(new MagicLinkMail($user->name, $magicLinkUrl));
    }
}
