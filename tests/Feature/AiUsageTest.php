<?php

namespace Tests\Feature;

use App\Enums\AiCallKind;
use App\Models\AiCall;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use FakesOpenAi, RefreshDatabase;

    public function test_usage_counts_this_months_ai_calls_per_kind(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 12:00', 'UTC'));
        $learner = $this->learner();
        $this->callAt($learner, AiCallKind::TutorReply, '2026-10-05 09:00');
        $this->callAt($learner, AiCallKind::TutorReply, '2026-10-12 09:00');
        $this->callAt($learner, AiCallKind::CardGeneration, '2026-10-12 10:00');
        $this->callAt($learner, AiCallKind::AnswerCheck, '2026-10-13 10:00');
        $this->callAt($learner, AiCallKind::SubtopicSuggestions, '2026-10-14 10:00');
        $this->callAt($learner, AiCallKind::TutorReply, '2026-09-30 09:00');

        $response = $this->getJson('/api/v1/settings/usage');

        $response->assertOk()->assertExactJson([
            'month' => '2026-10',
            'tutor_replies' => 2,
            'answers_checked' => 1,
            'explain_feedback' => 0,
            'card_batches' => 1,
            'subtopic_suggestions' => 1,
            'total' => 5,
        ]);
    }

    public function test_the_month_follows_the_learners_timezone(): void
    {
        // Already 1 November in Amsterdam, still October in UTC.
        $this->travelTo(Carbon::parse('2026-10-31 23:30', 'UTC'));
        $learner = $this->learner('Europe/Amsterdam');
        $this->callAt($learner, AiCallKind::TutorReply, '2026-10-31 23:10');
        $this->callAt($learner, AiCallKind::TutorReply, '2026-10-31 22:50');

        $this->getJson('/api/v1/settings/usage')
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('tutor_replies', 1)
            ->assertJsonPath('total', 1);
    }

    public function test_a_learner_never_sees_someone_elses_calls(): void
    {
        AiCall::factory()->create();
        $this->learner();

        $this->getJson('/api/v1/settings/usage')->assertJsonPath('total', 0);
    }

    public function test_every_tutor_reply_is_logged_as_an_ai_call_and_failures_are_not(): void
    {
        $learner = $this->learnerWithAiConfigured();
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Overloaded.', 'type' => 'server_error']], 500)
            ->whenEmpty($this->openAiAnswer($this->tutorReply()))]);

        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertServiceUnavailable();
        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertCreated();

        $call = $learner->aiCalls()->sole();
        $this->assertSame(AiCallKind::TutorReply, $call->kind);
        $this->assertSame('gpt-5.6-terra', $call->model);
        $this->assertSame([1200, 150], [$call->input_tokens, $call->output_tokens]);
    }

    private function learner(string $timezone = 'UTC'): User
    {
        $learner = User::factory()->create();
        UserSetting::factory()->for($learner)->create(['timezone' => $timezone]);
        Sanctum::actingAs($learner);

        return $learner;
    }

    private function callAt(User $learner, AiCallKind $kind, string $utcTime): void
    {
        AiCall::factory()->for($learner)->create(['kind' => $kind, 'created_at' => $utcTime]);
    }
}
