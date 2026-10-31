<?php

namespace Database\Factories;

use App\Enums\AiLevel;
use App\Enums\AvatarColor;
use App\Enums\ReminderChannel;
use App\Enums\Theme;
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
            'learner_role' => null,
            'avatar_color' => AvatarColor::Sun,
            'ai_level' => AiLevel::Medium,
            'wants_daily_reminder' => false,
            'timezone' => 'UTC',
            'daily_goal_cards' => 20,
            'session_size' => 20,
            'interleave_subtopics' => true,
            'hint_before_answer' => true,
            'save_chats_as_material' => true,
            'reminder_time' => '19:00:00',
            'wants_streak_reminder' => false,
            'wants_weekly_insights' => false,
            'reminder_channel' => ReminderChannel::Email,
            'theme' => Theme::System,
            'reduce_motion' => false,
            'sound_effects' => true,
        ];
    }
}
