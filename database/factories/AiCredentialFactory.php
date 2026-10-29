<?php

namespace Database\Factories;

use App\Enums\AiProvider;
use App\Models\AiCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiCredential>
 */
class AiCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $apiKey = 'sk-test-'.Str::random(40);

        return [
            'user_id' => User::factory(),
            'provider' => AiProvider::OpenAi,
            'encrypted_api_key' => $apiKey,
            'key_last_four' => substr($apiKey, -4),
            'model' => null,
            'validated_at' => now(),
        ];
    }
}
