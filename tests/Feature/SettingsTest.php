<?php

namespace Tests\Feature;

use App\Enums\AiLevel;
use App\Enums\Theme;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cant_read_or_change_settings(): void
    {
        $this->getJson('/api/v1/settings')->assertUnauthorized();
        $this->patchJson('/api/v1/settings', ['theme' => 'dark'])->assertUnauthorized();
    }

    public function test_a_new_learner_gets_the_defaults(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Sam', 'email' => 'sam@example.com'])->assertAccepted();
        Sanctum::actingAs(User::where('email', 'sam@example.com')->sole());

        $response = $this->getJson('/api/v1/settings');

        $response->assertOk()->assertExactJson([
            'learner_role' => null,
            'avatar_color' => 'sun',
            'ai_level' => 'medium',
            'daily_goal_cards' => 20,
            'session_size' => 20,
            'interleave_subtopics' => true,
            'hint_before_answer' => true,
            'save_chats_as_material' => true,
            'wants_daily_reminder' => false,
            'reminder_time' => '19:00',
            'wants_streak_reminder' => false,
            'wants_weekly_insights' => false,
            'reminder_channel' => 'email',
            'theme' => 'system',
            'reduce_motion' => false,
            'sound_effects' => true,
            'timezone' => 'UTC',
        ]);
    }

    public function test_a_learner_changes_every_setting(): void
    {
        $learner = $this->learner();

        $response = $this->patchJson('/api/v1/settings', [
            'learner_role' => 'career_switcher',
            'avatar_color' => 'mint',
            'ai_level' => 'high',
            'daily_goal_cards' => 40,
            'session_size' => 10,
            'interleave_subtopics' => false,
            'hint_before_answer' => false,
            'save_chats_as_material' => false,
            'wants_daily_reminder' => true,
            'reminder_time' => '07:30',
            'wants_streak_reminder' => true,
            'wants_weekly_insights' => true,
            'reminder_channel' => 'both',
            'theme' => 'dark',
            'reduce_motion' => true,
            'sound_effects' => false,
            'timezone' => 'Europe/Amsterdam',
        ]);

        $response->assertOk()
            ->assertJsonPath('learner_role', 'career_switcher')
            ->assertJsonPath('avatar_color', 'mint')
            ->assertJsonPath('ai_level', 'high')
            ->assertJsonPath('session_size', 10)
            ->assertJsonPath('reminder_time', '07:30')
            ->assertJsonPath('reminder_channel', 'both')
            ->assertJsonPath('theme', 'dark')
            ->assertJsonPath('timezone', 'Europe/Amsterdam');
        $this->assertSame(AiLevel::High, $learner->settings->fresh()->ai_level);
        $this->getJson('/api/v1/user')->assertJsonPath('avatar_color', 'mint')->assertJsonPath('wants_daily_reminder', true);
    }

    public function test_saving_one_tab_leaves_the_other_settings_alone(): void
    {
        $learner = $this->learner();
        $learner->settings->update(['ai_level' => AiLevel::Low, 'daily_goal_cards' => 40]);

        $this->patchJson('/api/v1/settings', ['theme' => 'light'])->assertOk();

        $settings = $learner->settings->fresh();
        $this->assertSame(Theme::Light, $settings->theme);
        $this->assertSame(AiLevel::Low, $settings->ai_level);
        $this->assertSame(40, $settings->daily_goal_cards);
    }

    public function test_settings_must_be_picked_from_the_list(): void
    {
        $this->learner();

        $response = $this->patchJson('/api/v1/settings', [
            'learner_role' => 'astronaut',
            'avatar_color' => 'purple',
            'ai_level' => 'max',
            'daily_goal_cards' => 15,
            'session_size' => 'lots',
            'interleave_subtopics' => 'maybe',
            'reminder_time' => '7pm',
            'reminder_channel' => 'pigeon',
            'theme' => 'neon',
            'timezone' => 'Mars/Olympus',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'learner_role' => 'Pick what brings you to Sprout from the list.',
            'avatar_color' => 'Pick a colour from the list.',
            'ai_level' => 'Pick low, medium or high.',
            'daily_goal_cards' => 'Pick a daily goal of 10, 20 or 40 cards.',
            'session_size' => 'Pick a session size of 10, 20 or 40.',
            'interleave_subtopics' => 'Turn this on or off.',
            'reminder_time' => 'Pick a time like 19:00.',
            'reminder_channel' => 'Pick push, email or both.',
            'theme' => 'Pick light, dark or match system.',
            'timezone' => "We don't know that timezone. Pick one from the list.",
        ]);
    }

    private function learner(): User
    {
        $learner = User::factory()->create();
        UserSetting::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        return $learner;
    }
}
