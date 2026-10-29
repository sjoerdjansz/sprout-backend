<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Message;
use App\Models\StudySession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'study_session_id' => StudySession::factory(),
            'role' => MessageRole::User,
            'content' => fake()->sentence(),
            'technique' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ];
    }
}
