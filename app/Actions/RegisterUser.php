<?php

namespace App\Actions;

use App\Enums\Interest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterUser
{
    public function __construct(
        private SendMagicLink $sendMagicLink,
        private CreateStarterCategories $createStarterCategories,
    ) {}

    /**
     * @param  array<int, Interest>  $interests
     */
    public function handle(string $name, string $email, array $interests, bool $wantsDailyReminder): void
    {
        $existingUser = User::firstWhere('email', $email);

        // Signing up with an email that already has an account sends that account a sign in link
        // instead of an error, so the API doesn't reveal who has an account.
        if ($existingUser !== null) {
            $this->sendMagicLink->handle($existingUser);

            return;
        }

        $user = DB::transaction(function () use ($name, $email, $interests, $wantsDailyReminder): User {
            $user = User::create(['name' => $name, 'email' => $email]);

            $user->settings()->create([
                'interests' => $interests,
                'wants_daily_reminder' => $wantsDailyReminder,
            ]);

            $this->createStarterCategories->handle($user);

            return $user;
        });

        $this->sendMagicLink->handle($user);
    }
}
