<?php

namespace Tests\Feature;

use App\Enums\ContentOrigin;
use App\Enums\ReviewRating;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FlashcardsTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Topic $topic;

    private Subtopic $subtopic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->learner = User::factory()->create();
        $this->topic = Topic::factory()->for($this->learner)->create();
        $this->subtopic = Subtopic::factory()->for($this->topic)->create();
    }

    public function test_guest_cannot_use_flashcards(): void
    {
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards")->assertUnauthorized();
    }

    public function test_the_cards_of_a_subtopic_come_with_their_schedule(): void
    {
        $this->travelTo('2026-10-30 12:00:00');
        $reviewed = $this->card(['front' => 'What does chlorophyll absorb?', 'interval_days' => 4, 'due_at' => now()]);
        FlashcardReview::factory()->for($reviewed)->create(['rating' => ReviewRating::Hard, 'reviewed_at' => now()->subDays(8)]);
        FlashcardReview::factory()->for($reviewed)->create(['rating' => ReviewRating::Good, 'reviewed_at' => now()->subDays(4)]);
        $overdue = $this->card(['due_at' => now()->subDays(3)]);
        $later = $this->card(['due_at' => now()->addDays(2)]);
        $paused = $this->card(['due_at' => now()->subDay(), 'suspended_at' => now()->subDays(2), 'origin' => ContentOrigin::User]);
        $this->card(['subtopic_id' => Subtopic::factory()->for($this->topic)]);
        Sanctum::actingAs($this->learner);

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards")
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.id', $reviewed->id)
            ->assertJsonPath('0.front', 'What does chlorophyll absorb?')
            ->assertJsonPath('0.origin', 'ai')
            ->assertJsonPath('0.is_due', true)
            ->assertJsonPath('0.due_on', '2026-10-30')
            ->assertJsonPath('0.last_rating', 'good')
            // Reviewed one interval ago: SM-2 schedules it when recall has dropped to about 90%.
            ->assertJsonPath('0.recall', 90)
            ->assertJsonPath('1.id', $overdue->id)
            ->assertJsonPath('1.due_on', '2026-10-30')
            ->assertJsonPath('1.last_rating', null)
            ->assertJsonPath('1.recall', null)
            ->assertJsonPath('2.id', $later->id)
            ->assertJsonPath('2.is_due', false)
            ->assertJsonPath('2.due_on', '2026-11-01')
            ->assertJsonPath('3.id', $paused->id)
            ->assertJsonPath('3.paused', true)
            ->assertJsonPath('3.is_due', false)
            ->assertJsonPath('3.origin', 'user');
    }

    public function test_due_today_follows_the_learners_timezone(): void
    {
        $this->travelTo('2026-10-30 22:30:00');
        UserSetting::factory()->for($this->learner)->create(['timezone' => 'Europe/Amsterdam']);
        $this->card(['due_at' => now()->addHour()]);
        Sanctum::actingAs($this->learner);

        // Due at 23:30 UTC, which is still today in UTC but already tomorrow in Amsterdam.
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards")
            ->assertJsonPath('0.is_due', false)
            ->assertJsonPath('0.due_on', '2026-10-31');
    }

    public function test_a_card_you_add_is_yours_and_due_straight_away(): void
    {
        Sanctum::actingAs($this->learner);

        $response = $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards", [
            'front' => 'Where does photosynthesis happen?',
            'back' => 'In the chloroplasts.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtopic_id', $this->subtopic->id)
            ->assertJsonPath('front', 'Where does photosynthesis happen?')
            ->assertJsonPath('back', 'In the chloroplasts.')
            ->assertJsonPath('origin', 'user')
            ->assertJsonPath('paused', false)
            ->assertJsonPath('is_due', true)
            ->assertJsonPath('recall', null);

        $flashcard = Flashcard::findOrFail($response->json('id'));
        $this->assertSame($this->learner->id, $flashcard->user_id);
        $this->assertSame($this->topic->id, $flashcard->topic_id);
        $this->assertNull($flashcard->source_message_id);
    }

    public function test_a_card_needs_a_front_and_a_back_that_arent_too_long(): void
    {
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'front' => 'Write the question or prompt for the front.',
                'back' => 'Write the answer for the back.',
            ]);

        $card = $this->card();
        $this->putJson("/api/v1/flashcards/{$card->id}", ['front' => str_repeat('a', 501), 'back' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'front' => 'Keep the front under 500 characters.',
                'back' => 'Keep the back under 2,000 characters.',
            ]);

        $this->assertDatabaseCount('flashcards', 1);
    }

    public function test_editing_a_card_keeps_its_schedule_and_history(): void
    {
        $card = $this->card(['front' => 'Old?', 'due_at' => now()->addDays(5)->startOfSecond(), 'interval_days' => 6, 'repetitions' => 3]);
        FlashcardReview::factory()->for($card)->create(['rating' => ReviewRating::Easy]);
        Sanctum::actingAs($this->learner);

        $this->putJson("/api/v1/flashcards/{$card->id}", ['front' => 'New?', 'back' => 'New answer.'])
            ->assertOk()
            ->assertJsonPath('front', 'New?')
            ->assertJsonPath('back', 'New answer.')
            ->assertJsonPath('last_rating', 'easy');

        $edited = $card->fresh();
        $this->assertTrue($edited->due_at->equalTo($card->due_at));
        $this->assertSame(6, $edited->interval_days);
        $this->assertSame(3, $edited->repetitions);
        $this->assertSame(ContentOrigin::Ai, $edited->origin);
    }

    public function test_a_paused_card_is_not_due_and_resuming_keeps_its_due_date(): void
    {
        $card = $this->card(['due_at' => now()->subDays(2)]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/pause")
            ->assertOk()
            ->assertJsonPath('paused', true)
            ->assertJsonPath('is_due', false);
        $pausedAt = $card->fresh()->suspended_at;
        $this->assertNotNull($pausedAt);

        $this->travel(1)->hours();
        $this->postJson("/api/v1/flashcards/{$card->id}/pause")->assertOk();
        $this->assertTrue($card->fresh()->suspended_at->equalTo($pausedAt));

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}")->assertJsonPath('due_cards', 0);

        $this->postJson("/api/v1/flashcards/{$card->id}/resume")
            ->assertOk()
            ->assertJsonPath('paused', false)
            ->assertJsonPath('is_due', true);
        $this->assertNull($card->fresh()->suspended_at);
    }

    public function test_deleting_a_card_removes_its_review_history(): void
    {
        $card = $this->card();
        FlashcardReview::factory()->for($card)->count(2)->create();
        $otherCard = $this->card();
        Sanctum::actingAs($this->learner);

        $this->deleteJson("/api/v1/flashcards/{$card->id}")->assertNoContent();

        $this->assertModelMissing($card);
        $this->assertDatabaseCount('flashcard_reviews', 0);
        $this->assertModelExists($otherCard);
    }

    public function test_someone_elses_card_is_not_found(): void
    {
        $someoneElses = Flashcard::factory()->create(['front' => 'Theirs?']);
        Sanctum::actingAs($this->learner);

        $this->putJson("/api/v1/flashcards/{$someoneElses->id}", ['front' => 'Mine?', 'back' => 'Mine.'])->assertNotFound();
        $this->postJson("/api/v1/flashcards/{$someoneElses->id}/pause")->assertNotFound();
        $this->postJson("/api/v1/flashcards/{$someoneElses->id}/resume")->assertNotFound();
        $this->deleteJson("/api/v1/flashcards/{$someoneElses->id}")->assertNotFound();

        $this->assertSame('Theirs?', $someoneElses->fresh()->front);
        $this->assertNull($someoneElses->fresh()->suspended_at);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Flashcard
    {
        return Flashcard::factory()->for($this->learner)->for($this->topic)->create([
            'subtopic_id' => $this->subtopic->id,
            ...$attributes,
        ]);
    }
}
