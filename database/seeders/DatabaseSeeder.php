<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Two local test accounts; sign in with a magic link from Mailpit (http://localhost:8025).
     * Meant for a fresh database: `php artisan migrate:fresh --seed`.
     */
    public function run(): void
    {
        $this->call([
            ActiveLearnerSeeder::class,
            NewLearnerSeeder::class,
        ]);
    }
}
