<?php

namespace Tests\Feature;

use App\Enums\AiLevel;
use App\Enums\LearnerRole;
use App\Enums\MessageRole;
use App\Enums\SessionKind;
use App\Models\AiCredential;
use App\Models\Flashcard;
use App\Models\Message;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class TutorConversationTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_guest_cannot_use_conversations(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertUnauthorized();
    }

    public function test_first_message_starts_a_conversation_with_the_tutors_reply(): void
    {
        $learner = $this->learnerWithAiConfigured();
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        $response = $this->postJson('/api/v1/conversations', ['message' => 'I want to learn more about biomechanics']);

        $response->assertCreated()
            ->assertJsonPath('title', 'Biomechanics')
            ->assertJsonPath('topic', null)
            ->assertJsonPath('is_saved', false)
            ->assertJsonPath('draft.title', 'Biomechanics')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'I want to learn more about biomechanics')
            ->assertJsonPath('messages.1.role', 'tutor')
            ->assertJsonPath('messages.1.content', 'What do you already know about **biomechanics**?');

        $conversation = StudySession::sole();
        $this->assertSame(SessionKind::Learn, $conversation->kind);
        $this->assertTrue($conversation->user->is($learner));

        $tutorMessage = $conversation->messages()->where('role', MessageRole::Tutor)->sole();
        $this->assertSame('gpt-5.6-terra', $tutorMessage->model);
        $this->assertSame(1200, $tutorMessage->input_tokens);
        $this->assertSame(150, $tutorMessage->output_tokens);
    }

    public function test_the_tutor_is_asked_with_sprouts_key_and_the_learners_level_and_profile(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $learner->settings->update(['ai_level' => AiLevel::Low, 'learner_role' => LearnerRole::CareerSwitcher]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        $this->postJson('/api/v1/conversations', ['message' => 'Teach me biomechanics'])->assertCreated();

        Http::assertSent(function (Request $request) use ($learner): bool {
            $context = json_decode(substr($request['messages'][1]['content'], strlen('Context: ')), true);

            return $request->hasHeader('Authorization', 'Bearer '.self::SPROUT_API_KEY)
                && $request['model'] === config('ai.levels.low.chat_model')
                && $request['safety_identifier'] === $learner->aiSafetyIdentifier()
                && $request['response_format']['json_schema']['strict'] === true
                // The provider never learns who the learner is: no name, only the safety identifier.
                && ! array_key_exists('name', $context['learner'])
                && ! str_contains($request['messages'][1]['content'], 'Ada')
                && $context['learner']['role'] === 'career_switcher'
                && $context['saved_topic'] === null
                && $request['messages'][2] === ['role' => 'user', 'content' => 'Teach me biomechanics'];
        });
    }

    public function test_continuing_a_topic_starts_from_its_saved_goal_and_subtopics(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create([
            'title' => 'Knee injuries',
            'goal' => 'Explain the common knee injuries to a client',
            'goal_deadline' => now()->addMonth(),
            'goal_recall_percentage' => 80,
        ]);
        $subtopic = Subtopic::factory()->for($topic)->create(['title' => 'Patellofemoral pain syndrome', 'content' => 'Pain around the kneecap.']);
        SubtopicProgress::factory()->for($learner)->for($subtopic)->create(['mastery' => 40]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        $response = $this->postJson('/api/v1/conversations', ['message' => 'Let us go deeper', 'topic_id' => $topic->id]);

        $response->assertCreated()
            ->assertJsonPath('topic.id', $topic->id)
            ->assertJsonPath('title', 'Knee injuries')
            ->assertJsonPath('is_saved', false);

        Http::assertSent(function (Request $request) use ($topic): bool {
            $context = json_decode(substr($request['messages'][1]['content'], strlen('Context: ')), true);

            return $context['learner']['categories'] === [$topic->category->name]
                && $context['draft']['category'] === $topic->category->name
                && $context['saved_topic']['goal']['recall_percentage'] === 80
                && $context['saved_topic']['subtopics'] === [['title' => 'Patellofemoral pain syndrome', 'mastery_percentage' => 40]]
                && $context['draft']['subtopics'] === [['title' => 'Patellofemoral pain syndrome', 'content' => 'Pain around the kneecap.']];
        });
    }

    public function test_the_safety_identifier_tells_learners_apart_without_saying_who_they_are(): void
    {
        $learner = User::factory()->create(['email' => 'ada@example.com']);
        $otherLearner = User::factory()->create();

        $identifier = $learner->aiSafetyIdentifier();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $identifier);
        $this->assertSame($identifier, $learner->fresh()->aiSafetyIdentifier());
        $this->assertNotSame($identifier, $otherLearner->aiSafetyIdentifier());
        $this->assertNotSame(hash('sha256', (string) $learner->id), $identifier);
        $this->assertNotSame(hash('sha256', 'ada@example.com'), $identifier);
    }

    public function test_a_conversation_cannot_continue_someone_elses_topic(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $someoneElsesTopic = Topic::factory()->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson('/api/v1/conversations', ['message' => 'Hi', 'topic_id' => $someoneElsesTopic->id]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id' => "We couldn't find that topic. Pick one from your knowledge map."]);
        Http::assertNothingSent();
    }

    public function test_a_message_must_be_filled_in_and_not_too_long(): void
    {
        Sanctum::actingAs($this->learnerWithAiConfigured());
        Http::fake();

        $this->postJson('/api/v1/conversations', ['message' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'Type a message first.']);

        $this->postJson('/api/v1/conversations', ['message' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'Keep your message under 2,000 characters. Split it into a few messages.']);

        Http::assertNothingSent();
    }

    public function test_a_message_continues_the_conversation_and_updates_the_draft(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        Sanctum::actingAs($learner);
        $updatedDraft = [
            'title' => 'Biomechanics of running',
            'category' => 'Sport',
            'goal' => ['description' => 'Explain running gait', 'deadline' => '2026-12-01', 'recall_percentage' => 80],
            'subtopics' => [['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']],
        ];
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply('Nice. What happens in the stance phase?', $updatedDraft))]);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['message' => 'Running, for my coaching']);

        $response->assertCreated()
            ->assertJsonPath('draft', $updatedDraft)
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.content', 'Running, for my coaching')
            ->assertJsonPath('messages.1.content', 'Nice. What happens in the stance phase?');
        $this->assertSame($updatedDraft, $conversation->fresh()->draft);
    }

    public function test_only_the_last_ten_messages_are_sent_to_the_tutor(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        Message::factory()->count(12)->for($conversation)->sequence(fn ($sequence): array => [
            'role' => MessageRole::User,
            'content' => 'Message '.($sequence->index + 1),
        ])->create();
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['message' => 'Newest'])->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];
            $history = array_slice($messages, 2, -1);

            return count($history) === 10
                && $history[0]['content'] === 'Message 3'
                && $history[9]['content'] === 'Message 12'
                && end($messages)['content'] === 'Newest';
        });
    }

    public function test_a_saved_conversation_is_read_only(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $savedConversation = StudySession::factory()->for($learner)->create(['ended_at' => now()]);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson("/api/v1/conversations/{$savedConversation->id}/messages", ['message' => 'More please']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'This conversation is saved. Use "Continue learning" to add to it.']);
        Http::assertNothingSent();
    }

    public function test_someone_elses_conversation_is_not_found(): void
    {
        $someoneElsesConversation = $this->openConversation(User::factory()->create());
        Sanctum::actingAs($this->learnerWithAiConfigured());
        Http::fake();

        $this->getJson("/api/v1/conversations/{$someoneElsesConversation->id}")->assertNotFound();
        $this->postJson("/api/v1/conversations/{$someoneElsesConversation->id}/messages", ['message' => 'Hi'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_sprouts_own_key_is_used_even_when_the_learner_once_connected_a_key(): void
    {
        $learner = $this->learnerWithAiConfigured();
        AiCredential::factory()->for($learner)->create(['encrypted_api_key' => 'sk-old-learner-key']);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.self::SPROUT_API_KEY));
    }

    public function test_without_a_configured_key_the_learner_hears_sprouts_ai_is_out_of_service(): void
    {
        $learner = $this->learnerWithAiConfigured();
        config(['services.openai.key' => null]);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson('/api/v1/conversations', ['message' => 'Hi']);

        $response->assertServiceUnavailable()
            ->assertExactJson(['message' => "Sprout's AI isn't working right now. We're fixing it, so try again later."]);
        $this->assertDatabaseCount('study_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_a_rejected_key_or_missing_credit_is_logged_and_shown_as_out_of_service(): void
    {
        Sanctum::actingAs($this->learnerWithAiConfigured());
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error']], 401)
            ->push(['error' => ['message' => 'You have no credits remaining.', 'type' => 'insufficient_quota']], 429)]);

        $outOfService = ['message' => "Sprout's AI isn't working right now. We're fixing it, so try again later."];
        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertServiceUnavailable()->assertExactJson($outOfService);
        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertServiceUnavailable()->assertExactJson($outOfService);

        $this->assertDatabaseCount('study_sessions', 0);
        Log::shouldHaveReceived('error')->twice();
    }

    public function test_provider_trouble_asks_to_try_again_and_stores_nothing(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        Sanctum::actingAs($learner);
        $tryAgain = ['message' => "The tutor can't answer right now. Try again in a moment."];

        foreach ([
            fn () => $this->openAiError(500, 'server_error'),
            fn () => $this->openAiError(429, 'requests', 'Rate limit reached.'),
            fn () => throw new ConnectionException('Timed out'),
            fn () => Http::response(['model' => 'gpt-5.6-terra', 'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"reply": "Cut o']]]]),
        ] as $providerTrouble) {
            Http::fake(['api.openai.com/*' => $providerTrouble]);

            $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['message' => 'Hi'])
                ->assertServiceUnavailable()
                ->assertExactJson($tryAgain);
        }

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_the_chat_history_lists_the_learners_conversations_with_the_latest_first(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Photosynthesis']);
        $older = StudySession::factory()->for($learner)->for($topic)->create(['updated_at' => now()->subDays(2)]);
        $newer = $this->openConversation($learner);
        StudySession::factory()->for($learner)->create(['topic_id' => null, 'kind' => SessionKind::Review]);
        $this->openConversation(User::factory()->create());
        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/conversations');

        $response->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', $newer->id)
            ->assertJsonPath('0.title', 'Biomechanics')
            ->assertJsonPath('0.is_saved', false)
            ->assertJsonPath('1.id', $older->id)
            ->assertJsonPath('1.title', 'Photosynthesis')
            ->assertJsonPath('1.topic.title', 'Photosynthesis')
            ->assertJsonPath('1.is_saved', true)
            ->assertJsonMissingPath('0.messages');
    }

    public function test_the_conversations_about_one_topic_come_with_a_message_count_and_a_preview(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        $conversation = StudySession::factory()->for($learner)->for($topic)->create();
        $messages = Message::factory()->for($conversation)->count(4)->create();
        StudySession::factory()->for($learner)->for($topic)->create(['kind' => SessionKind::Review]);
        StudySession::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/conversations?topic_id={$topic->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $conversation->id)
            ->assertJsonPath('0.message_count', 4)
            ->assertJsonCount(3, '0.preview')
            ->assertJsonPath('0.preview.0.id', $messages[0]->id)
            ->assertJsonPath('0.preview.2.id', $messages[2]->id);

        $this->getJson('/api/v1/conversations?topic_id='.Topic::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id' => "We couldn't find that topic. Pick one from your knowledge map."]);
    }

    public function test_deleting_a_conversation_keeps_the_cards_made_from_it(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        $conversation = StudySession::factory()->for($learner)->for($topic)->create();
        $message = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor]);
        $flashcard = Flashcard::factory()->for($learner)->for($topic)->create(['source_message_id' => $message->id]);
        $someoneElses = $this->openConversation(User::factory()->create());
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/conversations/{$conversation->id}")->assertNoContent();
        $this->deleteJson("/api/v1/conversations/{$someoneElses->id}")->assertNotFound();

        $this->assertModelMissing($conversation);
        $this->assertModelMissing($message);
        $this->assertNull($flashcard->fresh()->source_message_id);
        $this->assertModelExists($someoneElses);
    }

    public function test_opening_a_conversation_shows_its_messages_in_order(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        $first = Message::factory()->for($conversation)->create(['role' => MessageRole::User, 'content' => 'First']);
        $second = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor, 'content' => 'Second']);
        Sanctum::actingAs($learner);

        $response = $this->getJson("/api/v1/conversations/{$conversation->id}");

        $response->assertOk()
            ->assertJsonPath('messages.0.id', $first->id)
            ->assertJsonPath('messages.1.id', $second->id)
            ->assertJsonPath('messages.1.role', 'tutor');
    }

    public function test_sending_messages_is_rate_limited_per_learner(): void
    {
        Sanctum::actingAs($this->learnerWithAiConfigured());
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->tutorReply())]);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/conversations', ['message' => 'Hi'])->assertCreated();
        }

        $this->postJson('/api/v1/conversations', ['message' => 'Hi'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', "You're sending messages quickly. Wait a moment and try again.");
    }

    private function openConversation(User $learner): StudySession
    {
        return StudySession::factory()->for($learner)->create([
            'topic_id' => null,
            'draft' => $this->tutorReply()['draft'],
            'ended_at' => null,
        ]);
    }
}
