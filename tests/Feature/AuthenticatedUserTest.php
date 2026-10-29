<?php

namespace Tests\Feature;

use App\Models\User;
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
