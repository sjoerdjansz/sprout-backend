<?php

namespace Database\Factories;

use App\Enums\AiCallKind;
use App\Models\AiCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
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
            'kind' => AiCallKind::TutorReply,
            'model' => 'gpt-5.6-terra',
            'input_tokens' => 1200,
            'output_tokens' => 150,
        ];
    }
}
