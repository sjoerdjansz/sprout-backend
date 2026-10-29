<?php

namespace Database\Seeders;

use App\Enums\Interest;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sam has just signed up: settings, but no AI key and nothing learned yet,
 * so every empty state and the "connect your key" flow can be tried.
 */
class NewLearnerSeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::factory()->create([
            'name' => 'Sam',
            'email' => 'sam@sprout.test',
        ]);

        $sam->settings()->create([
            'interests' => [Interest::Coding, Interest::History],
            'wants_daily_reminder' => false,
            'timezone' => 'Europe/Amsterdam',
        ]);
    }
}
