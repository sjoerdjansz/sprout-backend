<?php

namespace App\Actions;

use App\Mail\ConfirmEmailChangeMail;
use App\Models\EmailChange;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Starts changing a learner's email: the new address gets a confirmation link, and the email only
 * changes once that link is opened (ConfirmEmailChange).
 */
class RequestEmailChange
{
    public function handle(User $user, string $newEmail): void
    {
        $plainToken = Str::random(64);

        DB::transaction(function () use ($user, $newEmail, $plainToken): void {
            // Only the latest request counts, so an address typed by mistake can't be confirmed later.
            $user->emailChanges()->delete();

            $user->emailChanges()->create([
                'new_email' => $newEmail,
                'token_hash' => MagicLinkToken::hashToken($plainToken),
                'expires_at' => now()->addMinutes(EmailChange::LIFETIME_IN_MINUTES),
            ]);
        });

        $confirmUrl = config('app.frontend_url').'/settings/email/confirm?'.http_build_query(['token' => $plainToken]);

        Mail::to($newEmail)->send(new ConfirmEmailChangeMail($user->name, $confirmUrl));
    }
}
