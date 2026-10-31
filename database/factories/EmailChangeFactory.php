<?php

namespace Database\Factories;

use App\Models\EmailChange;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailChange>
 */
class EmailChangeFactory extends Factory
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
            'new_email' => fake()->unique()->safeEmail(),
            'token_hash' => MagicLinkToken::hashToken(Str::random(64)),
            'expires_at' => now()->addMinutes(EmailChange::LIFETIME_IN_MINUTES),
        ];
    }

    /**
     * Store the hash of a known plain token, so a test can confirm with it.
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
