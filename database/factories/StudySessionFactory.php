<?php

namespace Database\Factories;

use App\Enums\SessionKind;
use App\Models\StudySession;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudySession>
 */
class StudySessionFactory extends Factory
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
            'kind' => SessionKind::Learn,
            'started_at' => now()->subMinutes(20),
            'ended_at' => now(),
        ];
    }
}
