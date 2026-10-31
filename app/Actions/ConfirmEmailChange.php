<?php

namespace App\Actions;

use App\Mail\EmailChangedMail;
use App\Models\EmailChange;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Finishes an email change once the link sent to the new address is opened. The old address is told,
 * so a learner notices when someone else changed it.
 */
class ConfirmEmailChange
{
    public const INVALID_LINK_MESSAGE = 'This link has expired or was already used. Change your email again from your settings.';

    /**
     * @throws ValidationException when the link is unknown, expired or used, or the address got taken meanwhile.
     */
    public function handle(string $plainToken): User
    {
        $emailChange = EmailChange::usable()
            ->where('token_hash', MagicLinkToken::hashToken($plainToken))
            ->first();

        if ($emailChange === null) {
            throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
        }

        $user = $emailChange->user;
        $oldEmail = $user->email;

        DB::transaction(function () use ($emailChange, $user): void {
            // Claim the link with a conditional update, so two simultaneous clicks can't both apply it.
            $wasClaimed = EmailChange::whereKey($emailChange->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]) === 1;

            if (! $wasClaimed) {
                throw ValidationException::withMessages(['token' => self::INVALID_LINK_MESSAGE]);
            }

            if (User::where('email', $emailChange->new_email)->exists()) {
                throw ValidationException::withMessages([
                    'token' => 'That email got an account in the meantime. Change your email to another address.',
                ]);
            }

            // Opening the link proves the learner owns the new address.
            $user->forceFill(['email' => $emailChange->new_email, 'email_verified_at' => now()])->save();
        });

        Mail::to($oldEmail)->send(new EmailChangedMail($user->name, $user->email));

        return $user;
    }
}
