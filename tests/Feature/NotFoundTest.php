<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotFoundTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_FOUND = ['message' => "We couldn't find that. Check the link, or head back to your dashboard."];

    public function test_an_unknown_route_is_a_404_with_a_friendly_message(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND);
    }

    public function test_an_unknown_route_answers_json_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND);
    }

    public function test_a_record_that_does_not_exist_doesnt_reveal_the_model(): void
    {
        config(['app.debug' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/conversations/999')
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND);
    }

    public function test_someone_elses_record_looks_the_same_as_one_that_does_not_exist(): void
    {
        $someoneElsesConversation = StudySession::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/conversations/{$someoneElsesConversation->id}")
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND);
    }

    public function test_an_id_that_isnt_a_number_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/conversations/abc')
            ->assertNotFound()
            ->assertExactJson(self::NOT_FOUND);
    }
}
