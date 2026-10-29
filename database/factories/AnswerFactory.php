<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\Question;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question_id' => Question::factory(),
            'user_id' => User::factory(),
            'study_session_id' => null,
            'question_option_id' => null,
            'response' => fake()->sentence(),
            'score' => 80,
            'is_correct' => true,
            'feedback' => fake()->sentence(),
            'gaps' => null,
            'answered_at' => now(),
        ];
    }
}
