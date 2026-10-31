<?php

namespace App\Actions;

use App\Models\User;

/**
 * Changes how Sprout addresses the learner. The email has its own, confirmed flow (RequestEmailChange).
 */
class UpdateProfile
{
    public function handle(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user;
    }
}
