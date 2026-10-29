<?php

namespace Database\Factories;

use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtopicProgress>
 */
class SubtopicProgressFactory extends Factory
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
            'subtopic_id' => Subtopic::factory(),
            'mastery' => fake()->numberBetween(0, 100),
            'last_studied_at' => now(),
        ];
    }
}
