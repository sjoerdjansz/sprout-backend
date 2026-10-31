<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A review of everything due today, across topics (specs/practice-session.md).
 */
class DueReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Subtopic $verbs;

    private Subtopic $light;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-31 09:00:00');
        $this->learner = User::factory()->create();
        $spanish = Topic::factory()->for($this->learner)->create(['title' => 'Spanish']);
        $biology = Topic::factory()->for($this->learner)->create(['title' => 'Biology']);
        $this->verbs = Subtopic::factory()->for($spanish)->create(['title' => 'Verbs']);
        $this->light = Subtopic::factory()->for($biology)->create(['title' => 'Light']);
    }

    public function test_the_due_queue_has_every_unpaused_card_due_today_most_overdue_first(): void
    {
        $dueToday = $this->card($this->light, ['due_at' => '2026-10-31 20:00']);
        $overdue = $this->card($this->verbs, ['due_at' => '2026-10-28 09:00']);
        $alsoOverdue = $this->card($this->light, ['due_at' => '2026-10-30 09:00']);
        $this->card($this->verbs, ['due_at' => '2026-11-01 08:00']);
        $this->card($this->verbs, ['due_at' => '2026-10-29 09:00', 'suspended_at' => now()]);
        Flashcard::factory()->create(['due_at' => '2026-10-29 09:00']);
        Sanctum::actingAs($this->learner);

        $response = $this->getJson('/api/v1/flashcards/due')->assertOk();

        $this->assertSame([$overdue->id, $alsoOverdue->id, $dueToday->id], $response->json('*.id'));
        $response->assertJsonPath('0.topic_id', $this->verbs->topic_id)
            ->assertJsonPath('0.subtopic_id', $this->verbs->id)
            ->assertJsonPath('0.is_due', true)
            ->assertJsonPath('0.due_on', '2026-10-31');
    }

    public function test_the_due_queue_follows_the_learners_timezone(): void
    {
        // 09:00 UTC is 22:00 in Auckland: a card due at 12:00 UTC is due tomorrow there.
        UserSetting::factory()->for($this->learner)->create(['timezone' => 'Pacific/Auckland']);
        $dueTonight = $this->card($this->light, ['due_at' => '2026-10-31 10:00']);
        $this->card($this->light, ['due_at' => '2026-10-31 12:00']);
        Sanctum::actingAs($this->learner);

        $this->assertSame([$dueTonight->id], $this->getJson('/api/v1/flashcards/due')->json('*.id'));
    }

    public function test_the_due_queue_needs_a_token(): void
    {
        $this->getJson('/api/v1/flashcards/due')->assertUnauthorized();
    }

    public function test_a_due_review_practises_cards_from_several_topics(): void
    {
        SubtopicProgress::factory()->for($this->learner)->for($this->verbs)->create(['mastery' => 55]);
        $verbCard = $this->card($this->verbs);
        $lightCard = $this->card($this->light);
        $untouched = Subtopic::factory()->for($this->verbs->topic)->create();
        Sanctum::actingAs($this->learner);

        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->duePlan([$lightCard->id, $verbCard->id]))
            ->assertCreated()
            ->assertJsonPath('scope', 'due')
            ->assertJsonPath('topic', null)
            ->assertJsonPath('subtopic', null)
            ->assertJsonPath('plan.card_ids', [$lightCard->id, $verbCard->id])
            ->json('id');

        $session = StudySession::findOrFail($sessionId);
        $this->assertNull($session->topic_id);
        // Only the subtopics of its cards, not every subtopic of both topics.
        $this->assertEqualsCanonicalizing([$this->verbs->id => 55, $this->light->id => 0], $session->mastery_before);
        $this->assertArrayNotHasKey($untouched->id, $session->mastery_before);

        $this->postJson("/api/v1/flashcards/{$lightCard->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();
        $this->postJson("/api/v1/flashcards/{$verbCard->id}/reviews", ['rating' => 'again', 'practice_session_id' => $sessionId])->assertCreated();

        $this->postJson("/api/v1/practice-sessions/{$sessionId}/finish")
            ->assertOk()
            ->assertJsonPath('is_finished', true)
            ->assertJsonPath('xp_earned', 2)
            ->assertJsonPath('subtopics.0.id', $this->verbs->id)
            ->assertJsonPath('subtopics.0.topic', ['id' => $this->verbs->topic_id, 'title' => 'Spanish'])
            ->assertJsonPath('subtopics.0.mastery_before', 55)
            ->assertJsonPath('subtopics.1.id', $this->light->id)
            ->assertJsonPath('subtopics.1.topic', ['id' => $this->light->topic_id, 'title' => 'Biology']);

        // The Sessions tab of both subtopics lists it.
        $this->getJson("/api/v1/subtopics/{$this->verbs->id}/practice-sessions")->assertJsonPath('0.id', $sessionId);
        $this->getJson("/api/v1/subtopics/{$this->light->id}/practice-sessions")->assertJsonPath('0.id', $sessionId);
    }

    public function test_a_due_review_can_be_picked_up_after_a_reload(): void
    {
        $card = $this->card($this->verbs);
        Sanctum::actingAs($this->learner);

        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->duePlan([$card->id]))->json('id');

        $this->getJson('/api/v1/practice-sessions/current')
            ->assertOk()
            ->assertJsonPath('id', $sessionId)
            ->assertJsonPath('scope', 'due');
    }

    public function test_a_due_review_is_flashcards_only_from_your_own_cards(): void
    {
        $card = $this->card($this->verbs);
        $someoneElsesCard = Flashcard::factory()->create();
        $question = Question::factory()->for($this->verbs->topic)->create(['subtopic_id' => $this->verbs->id, 'type' => QuestionType::Open]);
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', [
            ...$this->duePlan([$card->id]),
            'modes' => ['cards', 'retrieval'],
            'question_ids' => [$question->id],
            'explain_subtopic_id' => $this->verbs->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'modes' => 'A review of everything due is flashcards only.',
                'question_ids' => 'A review of everything due has no quiz questions.',
                'explain_subtopic_id' => 'A review of everything due has nothing to explain.',
            ]);

        $this->postJson('/api/v1/practice-sessions', $this->duePlan([$card->id, $someoneElsesCard->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids' => 'Pick cards from what you\'re practising.']);

        $this->postJson('/api/v1/practice-sessions', $this->duePlan([]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_ids' => 'Nothing is due today, so there is nothing to review.']);

        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_a_due_review_has_no_topic_and_the_scope_must_be_known(): void
    {
        $card = $this->card($this->verbs);
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', [...$this->duePlan([$card->id]), 'topic_id' => $this->verbs->topic_id, 'subtopic_id' => $this->verbs->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'topic_id' => 'A review of everything due covers all your topics. Leave the topic out.',
                'subtopic_id' => 'A review of everything due covers all your topics. Leave the subtopic out.',
            ]);

        $this->postJson('/api/v1/practice-sessions', [...$this->duePlan([$card->id]), 'scope' => 'everything'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope' => 'Pick what to practise: a topic, or everything due today.']);

        // Without a scope, a topic is still needed, as before.
        $this->postJson('/api/v1/practice-sessions', ['modes' => ['cards'], 'card_ids' => [], 'question_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id' => 'Pick one of your topics to practise.']);

        $this->assertDatabaseCount('study_sessions', 0);
    }

    public function test_a_due_review_has_no_open_session(): void
    {
        $card = $this->card($this->verbs);
        Sanctum::actingAs($this->learner);
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->duePlan([$card->id]))->json('id');

        $this->postJson("/api/v1/practice-sessions/{$sessionId}/messages", ['message' => 'Quiz me'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message' => 'A review of everything due has no open session. Start a session on one topic to talk it through.']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_deleting_a_topic_keeps_a_due_review_with_the_other_topics(): void
    {
        $verbCard = $this->card($this->verbs);
        $lightCard = $this->card($this->light);
        Sanctum::actingAs($this->learner);
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->duePlan([$verbCard->id, $lightCard->id]))->json('id');
        $this->postJson("/api/v1/flashcards/{$verbCard->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();
        $this->postJson("/api/v1/flashcards/{$lightCard->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();

        $this->deleteJson("/api/v1/topics/{$this->verbs->topic_id}")->assertNoContent();

        $this->getJson('/api/v1/practice-sessions/current')
            ->assertOk()
            ->assertJsonPath('id', $sessionId)
            ->assertJsonCount(1, 'done.flashcard_reviews')
            ->assertJsonPath('subtopics.0.id', $this->light->id);
        $this->assertSame(1, FlashcardReview::count());
    }

    /**
     * @param  array<int, int>  $cardIds
     * @return array<string, mixed>
     */
    private function duePlan(array $cardIds): array
    {
        return ['scope' => 'due', 'modes' => ['cards'], 'card_ids' => $cardIds, 'question_ids' => []];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(Subtopic $subtopic, array $attributes = []): Flashcard
    {
        return Flashcard::factory()->for($this->learner)->create([
            'topic_id' => $subtopic->topic_id,
            'subtopic_id' => $subtopic->id,
            'due_at' => now(),
            ...$attributes,
        ]);
    }
}
