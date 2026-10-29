<?php

namespace Database\Factories;

use App\Models\DailyActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyActivity>
 */
class DailyActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'activity_date' => today(),
            'xp_earned' => 40,
            'cards_reviewed' => 12,
            'questions_answered' => 3,
        ];
    }
}
