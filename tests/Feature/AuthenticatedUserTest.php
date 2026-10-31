<?php

namespace Tests\Feature;

use App\Enums\Interest;
use App\Models\DailyActivity;
use App\Models\User;
use App\Models\UserSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticatedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_receives_json_401_even_without_accept_header(): void
    {
        $response = $this->get('/api/v1/user');

        $response->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_user_receives_their_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonMissingPath('password');
    }

    public function test_profile_includes_the_sign_up_preferences_from_the_settings(): void
    {
        $user = User::factory()->create();
        UserSetting::factory()->for($user)->create([
            'interests' => [Interest::Maths, Interest::History],
            'wants_daily_reminder' => true,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user');

        $response->assertOk()
            ->assertJsonPath('interests', ['maths', 'history'])
            ->assertJsonPath('wants_daily_reminder', true);
    }

    public function test_profile_includes_the_current_and_the_best_streak(): void
    {
        $user = User::factory()->create();
        // Best run so far: 6 to 4 days ago. Now: the day before yesterday and yesterday.
        foreach ([1, 2, 4, 5, 6, 9] as $daysAgo) {
            DailyActivity::factory()->for($user)->create(['activity_date' => today()->subDays($daysAgo)]);
        }
        Sanctum::actingAs($user);

        // Nothing yet today: yesterday's streak still counts.
        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('streak_days', 2)
            ->assertJsonPath('best_streak_days', 3);

        DailyActivity::factory()->for($user)->create(['activity_date' => today()]);
        DailyActivity::factory()->for($user)->create(['activity_date' => today()->subDays(3)]);

        $this->getJson('/api/v1/user')
            ->assertJsonPath('streak_days', 7)
            ->assertJsonPath('best_streak_days', 7);
    }

    public function test_the_streak_is_lost_after_a_day_without_practice_but_the_best_stays(): void
    {
        $user = User::factory()->create();
        foreach ([2, 3, 4] as $daysAgo) {
            DailyActivity::factory()->for($user)->create(['activity_date' => today()->subDays($daysAgo)]);
        }
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')
            ->assertJsonPath('streak_days', 0)
            ->assertJsonPath('best_streak_days', 3);
    }

    public function test_the_streak_follows_the_learners_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:30', 'UTC'));
        $user = User::factory()->create();
        UserSetting::factory()->for($user)->create(['timezone' => 'Pacific/Auckland']);
        // 1 November in Auckland already: practice on 31 October was yesterday there.
        DailyActivity::factory()->for($user)->create(['activity_date' => '2026-10-31']);
        DailyActivity::factory()->for($user)->create(['activity_date' => '2026-10-30']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/user')->assertJsonPath('streak_days', 2);

        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'UTC'));

        $this->getJson('/api/v1/user')->assertJsonPath('streak_days', 0);
    }

    public function test_user_can_authenticate_with_a_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/user');

        $response->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_unknown_route_returns_json_404(): void
    {
        $response = $this->get('/');

        $response->assertNotFound()->assertJsonStructure(['message']);
    }
}
