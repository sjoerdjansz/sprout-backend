<?php

namespace Database\Factories;

use App\Enums\ContentOrigin;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
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
            'subtopic_id' => null,
            'type' => QuestionType::Open,
            'prompt' => fake()->sentence().'?',
            'model_answer' => fake()->sentence(),
            'origin' => ContentOrigin::Ai,
            'source_message_id' => null,
        ];
    }
}
