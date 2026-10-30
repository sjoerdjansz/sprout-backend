<?php

namespace App\Actions;

use App\Models\User;

/**
 * Gives a new learner the starter categories, so they can file topics without inventing categories
 * first. They mirror the sign-up interests (coding goes under Technology).
 */
class CreateStarterCategories
{
    public const NAMES = ['Science', 'Technology', 'Maths', 'Languages', 'History', 'Arts & culture'];

    public function handle(User $learner): void
    {
        foreach (self::NAMES as $name) {
            $learner->categories()->create(['name' => $name]);
        }
    }
}
