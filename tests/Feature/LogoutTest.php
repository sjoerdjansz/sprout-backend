<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('this-device')->plainTextToken;
        $user->createToken('other-device');

        $response = $this->withToken($currentToken)->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertSame(['other-device'], $user->tokens()->pluck('name')->all());
    }

    public function test_guest_cannot_log_out(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    }
}
