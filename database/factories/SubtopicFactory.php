<?php

namespace Database\Factories;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subtopic>
 */
class SubtopicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic_id' => Topic::factory(),
            'title' => fake()->words(3, true),
            'description' => null,
            'content' => fake()->paragraph(),
            'position' => fake()->numberBetween(1, 10),
        ];
    }
}
