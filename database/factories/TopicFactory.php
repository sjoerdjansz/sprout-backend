<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
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
            // A category of the topic's own learner, as the API guarantees.
            'category_id' => fn (array $attributes): int => Category::factory()->create(['user_id' => $attributes['user_id']])->id,
            'title' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'notes' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }
}
