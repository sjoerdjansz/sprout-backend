<?php

namespace Tests\Feature;

use App\Enums\LearnerRole;
use App\Enums\QuestionType;
use App\Enums\ReviewRating;
use App\Enums\SessionKind;
use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class PracticeMessagesTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    private User $learner;

    private Topic $topic;

    private Subtopic $subtopic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-31 09:00:00');
        $this->learner = $this->learnerWithAiConfigured();
        $this->learner->settings->update(['learner_role' => LearnerRole::Student]);
        $this->topic = Topic::factory()->for($this->learner)->create(['title' => 'Photosynthesis', 'goal' => 'Pass the biology exam']);
        $this->subtopic = Subtopic::factory()->for($this->topic)->withTutorNotes('Chlorophyll absorbs red and blue light.')->create(['title' => 'Light']);
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_talk_to_the_practice_tutor(): void
    {
        $session = $this->practiceSession();

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Quiz me'])->assertUnauthorized();
    }

    public function test_the_tutor_replies_and_the_turn_belongs_to_the_session(): void
    {
        $session = $this->practiceSession();
        Http::fake(['api.openai.com/*' => $this->openAiAnswer([
            'reply' => 'Before I explain: which colours does chlorophyll absorb?',
            'technique' => 'retrieval_practice',
        ])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Quiz me on light'])
            ->assertCreated()
            ->assertJsonPath('message.role', 'user')
            ->assertJsonPath('message.content', 'Quiz me on light')
            ->assertJsonPath('message.technique', null)
            ->assertJsonPath('reply.role', 'tutor')
            ->assertJsonPath('reply.content', 'Before I explain: which colours does chlorophyll absorb?')
            ->assertJsonPath('reply.technique', 'retrieval_practice');

        $this->assertSame(2, $session->messages()->count());
        $this->assertDatabaseHas('messages', ['study_session_id' => $session->id, 'role' => 'tutor', 'technique' => 'retrieval_practice']);
        $this->assertDatabaseHas('ai_calls', ['user_id' => $this->learner->id, 'kind' => 'tutor_reply']);

        // Not in the chat history, but part of the practice session.
        $this->getJson('/api/v1/conversations')->assertJsonCount(0);
        $this->getJson('/api/v1/practice-sessions/current')
            ->assertJsonPath('done.message_count', 2)
            ->assertJsonPath('done.messages.1.content', 'Before I explain: which colours does chlorophyll absorb?')
            ->assertJsonPath('done.messages.1.technique', 'retrieval_practice');
    }

    public function test_the_tutor_knows_the_cards_and_open_questions_weakest_first(): void
    {
        $strong = $this->card(['front' => 'What absorbs light?', 'back' => 'Chlorophyll.', 'interval_days' => 10]);
        FlashcardReview::factory()->for($strong)->create(['rating' => ReviewRating::Good, 'reviewed_at' => now()]);
        $this->card(['front' => 'Which colour is reflected?', 'back' => 'Green.']);
        $this->card(['front' => 'Paused card', 'suspended_at' => now()]);
        $answered = $this->openQuestion(['prompt' => 'Why are leaves green?', 'model_answer' => 'Green light is reflected.']);
        Answer::factory()->for($answered)->for($this->learner)->create(['score' => 80, 'answered_at' => now()->subDay()]);
        $this->openQuestion(['prompt' => 'Why do plants need light?', 'model_answer' => 'For energy.']);
        Question::factory()->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'type' => QuestionType::MultipleChoice]);
        SubtopicProgress::factory()->for($this->learner)->for($this->subtopic)->create(['mastery' => 45]);
        $session = $this->practiceSession();
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['reply' => 'What do you remember?', 'technique' => null])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Ask what I remember'])
            ->assertCreated()
            ->assertJsonPath('reply.technique', null);

        Http::assertSent(function (Request $request): bool {
            $context = json_decode(substr($request['messages'][1]['content'], strlen('Context: ')), true);

            return $request['messages'][0]['content'] === file_get_contents(resource_path('prompts/practice-tutor.md'))
                && $request['response_format']['json_schema']['name'] === 'practice_tutor_reply'
                && $context['learner'] === ['role' => 'student']
                && $context['topic'] === ['title' => 'Photosynthesis', 'goal' => 'Pass the biology exam']
                && $context['subtopic'] === ['title' => 'Light', 'notes' => "## From the chat\n\nChlorophyll absorbs red and blue light.", 'mastery_percentage' => 45]
                && $context['flashcards'] === [
                    ['subtopic' => 'Light', 'front' => 'Which colour is reflected?', 'back' => 'Green.', 'recall_percentage' => null],
                    ['subtopic' => 'Light', 'front' => 'What absorbs light?', 'back' => 'Chlorophyll.', 'recall_percentage' => 100],
                ]
                && $context['open_questions'] === [
                    ['subtopic' => 'Light', 'question' => 'Why do plants need light?', 'model_answer' => 'For energy.', 'last_score' => null],
                    ['subtopic' => 'Light', 'question' => 'Why are leaves green?', 'model_answer' => 'Green light is reflected.', 'last_score' => 80],
                ]
                // The provider never learns who the learner is.
                && ! str_contains($request['messages'][1]['content'], 'Ada')
                && $request['messages'][array_key_last($request['messages'])]['content'] === 'Ask what I remember';
        });
    }

    public function test_on_a_whole_topic_the_tutor_knows_every_subtopic(): void
    {
        $water = Subtopic::factory()->for($this->topic)->create(['title' => 'Water', 'position' => 2]);
        $this->subtopic->update(['position' => 1]);
        Flashcard::factory()->for($this->learner)->for($this->topic)->create(['subtopic_id' => $water->id, 'front' => 'Where does water enter?', 'back' => 'The roots.']);
        $session = $this->practiceSession(['subtopic_id' => null]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['reply' => 'Let us mix them.', 'technique' => 'interleaving'])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Mix it up'])->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $context = json_decode(substr($request['messages'][1]['content'], strlen('Context: ')), true);

            return $context['subtopic'] === null
                && $context['subtopics'] === [['title' => 'Light', 'mastery_percentage' => 0], ['title' => 'Water', 'mastery_percentage' => 0]]
                && $context['flashcards'] === [['subtopic' => 'Water', 'front' => 'Where does water enter?', 'back' => 'The roots.', 'recall_percentage' => null]];
        });
    }

    public function test_earlier_messages_of_the_session_are_sent_along(): void
    {
        $session = $this->practiceSession();
        Message::factory()->for($session, 'studySession')->create(['role' => 'user', 'content' => 'Quiz me']);
        Message::factory()->for($session, 'studySession')->create(['role' => 'tutor', 'content' => 'Which colours are absorbed?']);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['reply' => 'Yes, and green?', 'technique' => 'testing_effect'])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Red and blue'])->assertCreated();

        Http::assertSent(fn (Request $request): bool => array_slice($request['messages'], 2) === [
            ['role' => 'user', 'content' => 'Quiz me'],
            ['role' => 'assistant', 'content' => 'Which colours are absorbed?'],
            ['role' => 'user', 'content' => 'Red and blue'],
        ]);
    }

    public function test_a_session_with_only_messages_is_kept_and_listed_on_its_subtopic(): void
    {
        $session = $this->practiceSession();
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['reply' => 'Go on.', 'technique' => null])]);
        Sanctum::actingAs($this->learner);
        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Quiz me'])->assertCreated();

        $this->postJson("/api/v1/practice-sessions/{$session->id}/finish")->assertOk()->assertJsonPath('done.message_count', 2);

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/practice-sessions")
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $session->id);
    }

    public function test_a_finished_session_takes_no_more_messages(): void
    {
        $session = $this->practiceSession(['ended_at' => now()]);
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'One more'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'This practice session has ended. Start a new one to keep practising.']);

        Http::assertNothingSent();
    }

    public function test_nothing_is_stored_when_the_tutor_cannot_answer(): void
    {
        $session = $this->practiceSession();
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => 'Quiz me'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");

        $this->assertSame(0, Message::query()->count());
    }

    public function test_a_message_is_required(): void
    {
        $session = $this->practiceSession();
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'Type a message first.']);
        $this->postJson("/api/v1/practice-sessions/{$session->id}/messages", ['message' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'Keep your message under 2,000 characters. Split it into a few messages.']);

        Http::assertNothingSent();
    }

    public function test_only_your_own_practice_sessions_take_messages(): void
    {
        $someoneElses = StudySession::factory()->create(['kind' => SessionKind::Review, 'ended_at' => null]);
        $conversation = StudySession::factory()->for($this->learner)->create(['kind' => SessionKind::Learn, 'ended_at' => null]);
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$someoneElses->id}/messages", ['message' => 'Hi'])->assertNotFound();
        $this->postJson("/api/v1/practice-sessions/{$conversation->id}/messages", ['message' => 'Hi'])->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function practiceSession(array $attributes = []): StudySession
    {
        return StudySession::factory()->for($this->learner)->for($this->topic)->create([
            'kind' => SessionKind::Review,
            'subtopic_id' => $this->subtopic->id,
            'plan' => ['modes' => ['open'], 'card_ids' => [], 'question_ids' => [], 'explain_subtopic_id' => null],
            'started_at' => now(),
            'ended_at' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Flashcard
    {
        return Flashcard::factory()->for($this->learner)->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openQuestion(array $attributes): Question
    {
        return Question::factory()->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'type' => QuestionType::Open, ...$attributes]);
    }
}
