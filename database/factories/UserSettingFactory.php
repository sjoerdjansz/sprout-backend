<?php

namespace Database\Factories;

use App\Enums\AiLevel;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSetting>
 */
class UserSettingFactory extends Factory
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
            'interests' => [],
            'ai_level' => AiLevel::Medium,
            'wants_daily_reminder' => false,
            'timezone' => 'UTC',
            'daily_goal_cards' => 20,
            'reminder_time' => null,
        ];
    }
}
