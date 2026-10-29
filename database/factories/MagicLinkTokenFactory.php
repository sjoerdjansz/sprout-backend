<?php

namespace Database\Factories;

use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MagicLinkToken>
 */
class MagicLinkTokenFactory extends Factory
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
            'token_hash' => MagicLinkToken::hashToken(Str::random(64)),
            'expires_at' => now()->addMinutes(MagicLinkToken::LIFETIME_IN_MINUTES),
        ];
    }

    /**
     * Store the hash of a known plain token, so a test can sign in with it.
     */
    public function forPlainToken(string $plainToken): static
    {
        return $this->state(fn (array $attributes): array => [
            'token_hash' => MagicLinkToken::hashToken($plainToken),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes): array => [
            'used_at' => now()->subMinute(),
        ]);
    }
}
