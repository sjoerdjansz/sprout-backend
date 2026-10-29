<?php

namespace App\Actions;

use App\Models\User;

class RequestMagicLink
{
    public function __construct(private SendMagicLink $sendMagicLink) {}

    public function handle(string $email): void
    {
        $user = User::firstWhere('email', $email);

        // Unknown emails get the same response but no mail, so the API doesn't reveal who has an account.
        if ($user === null) {
            return;
        }

        $this->sendMagicLink->handle($user);
    }
}
