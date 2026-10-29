<?php

namespace Database\Factories;

use App\Enums\ContentOrigin;
use App\Models\Flashcard;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flashcard>
 */
class FlashcardFactory extends Factory
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
            'topic_id' => Topic::factory(),
            'subtopic_id' => null,
            'front' => fake()->sentence().'?',
            'back' => fake()->sentence(),
            'origin' => ContentOrigin::Ai,
            'source_message_id' => null,
            'due_at' => now(),
            'interval_days' => 0,
            'ease_factor' => 2.5,
            'repetitions' => 0,
            'lapses' => 0,
            'suspended_at' => null,
        ];
    }
}
