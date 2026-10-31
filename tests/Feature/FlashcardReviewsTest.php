<?php

namespace Tests\Feature;

use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlashcardReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Topic $topic;

    private Subtopic $subtopic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-30 12:00:00');
        $this->learner = User::factory()->create();
        $this->topic = Topic::factory()->for($this->learner)->create();
        $this->subtopic = Subtopic::factory()->for($this->topic)->create();
    }

    public function test_guest_cannot_review_a_card(): void
    {
        $card = $this->card();

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good'])->assertUnauthorized();
    }

    public function test_rating_a_new_card_schedules_it_and_keeps_the_review(): void
    {
        $card = $this->card();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good'])
            ->assertCreated()
            ->assertJsonPath('flashcard.id', $card->id)
            ->assertJsonPath('flashcard.is_due', false)
            ->assertJsonPath('flashcard.due_on', '2026-10-31')
            ->assertJsonPath('flashcard.last_rating', 'good')
            ->assertJsonPath('flashcard.recall', 100)
            // The rating bar for the next review: 1 day ago "good", so good is now 3 days.
            ->assertJsonPath('flashcard.next_review_in_minutes', ['again' => 10, 'hard' => 1440, 'good' => 4320, 'easy' => 5760])
            ->assertJsonPath('subtopic.id', $this->subtopic->id);

        $card->refresh();
        $this->assertSame(1, $card->interval_days);
        $this->assertSame(1, $card->repetitions);
        $this->assertTrue($card->due_at->equalTo(now()->addDay()));
        $this->assertDatabaseHas('flashcard_reviews', [
            'flashcard_id' => $card->id,
            'rating' => 'good',
            'interval_days_before' => 0,
            'interval_days_after' => 1,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: float, 3: int, 4: int}>
     */
    public static function ratingsOfAKnownCard(): array
    {
        // [rating, interval in days, ease, repetitions, lapses] after the rating, for a card at 3 days, ease 2.5, 2 repetitions.
        return [
            'again starts over and comes back in 10 minutes' => ['again', 0, 2.3, 0, 1],
            'hard grows the interval a little' => ['hard', 4, 2.35, 3, 0],
            'good multiplies by the ease' => ['good', 8, 2.5, 3, 0],
            'easy adds a bonus' => ['easy', 10, 2.65, 3, 0],
        ];
    }

    #[DataProvider('ratingsOfAKnownCard')]
    public function test_each_rating_follows_sm2(string $rating, int $interval, float $ease, int $repetitions, int $lapses): void
    {
        $card = $this->card(['interval_days' => 3, 'ease_factor' => 2.5, 'repetitions' => 2, 'lapses' => 0]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => $rating])->assertCreated();

        $card->refresh();
        $this->assertSame($interval, $card->interval_days);
        $this->assertSame($ease, $card->ease_factor);
        $this->assertSame($repetitions, $card->repetitions);
        $this->assertSame($lapses, $card->lapses);
        $expectedDueAt = $rating === 'again' ? now()->addMinutes(10) : now()->addDays($interval);
        $this->assertTrue($card->due_at->equalTo($expectedDueAt));
    }

    public function test_the_ease_never_drops_below_its_minimum(): void
    {
        $card = $this->card(['interval_days' => 5, 'ease_factor' => 1.4, 'repetitions' => 4]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'again'])->assertCreated();

        $this->assertSame(1.3, $card->refresh()->ease_factor);
    }

    public function test_the_cards_list_shows_what_each_rating_would_do(): void
    {
        $this->card();
        Sanctum::actingAs($this->learner);

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/flashcards")
            ->assertOk()
            ->assertJsonPath('0.next_review_in_minutes', ['again' => 10, 'hard' => 1440, 'good' => 1440, 'easy' => 5760]);
    }

    public function test_a_review_updates_the_subtopics_mastery(): void
    {
        $card = $this->card();
        SubtopicProgress::factory()->for($this->learner)->for($this->subtopic)->create(['mastery' => 10, 'peak_mastery' => 20]);
        Sanctum::actingAs($this->learner);

        // Only the cards facet is practised (good = 85), so mastery is 85 / 3.
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good'])
            ->assertCreated()
            ->assertJsonPath('subtopic.mastery', 28)
            ->assertJsonPath('subtopic.is_new', false)
            ->assertJsonPath('subtopic.facets.cards', ['percent' => 85, 'holding' => false])
            ->assertJsonPath('subtopic.facets.quiz', ['percent' => null, 'holding' => false]);

        $this->assertDatabaseHas('subtopic_progress', [
            'user_id' => $this->learner->id,
            'subtopic_id' => $this->subtopic->id,
            'mastery' => 28,
            'peak_mastery' => 28,
        ]);
    }

    public function test_mastery_keeps_the_highest_value_reached(): void
    {
        $card = $this->card();
        SubtopicProgress::factory()->for($this->learner)->for($this->subtopic)->create(['mastery' => 70, 'peak_mastery' => 80]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'again'])
            ->assertCreated()
            ->assertJsonPath('subtopic.mastery', 0);

        $this->assertDatabaseHas('subtopic_progress', ['subtopic_id' => $this->subtopic->id, 'mastery' => 0, 'peak_mastery' => 80]);
    }

    public function test_a_card_without_a_subtopic_has_no_subtopic_to_update(): void
    {
        $card = $this->card(['subtopic_id' => null]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'easy'])
            ->assertCreated()
            ->assertJsonPath('subtopic', null);
    }

    public function test_reviews_count_towards_todays_activity_in_the_learners_timezone(): void
    {
        // 00:30 in Amsterdam: already the next day there.
        $this->travelTo('2026-10-30 23:30:00');
        UserSetting::factory()->for($this->learner)->create(['timezone' => 'Europe/Amsterdam']);
        $first = $this->card();
        $second = $this->card();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$first->id}/reviews", ['rating' => 'again'])->assertCreated();
        $this->postJson("/api/v1/flashcards/{$second->id}/reviews", ['rating' => 'easy'])->assertCreated();

        $activity = DailyActivity::query()->where('user_id', $this->learner->id)->sole();
        $this->assertSame('2026-10-31', $activity->activity_date->toDateString());
        $this->assertSame(2, $activity->cards_reviewed);
        $this->assertSame(0, $activity->questions_answered);
        // XP is for the effort: a card rated "again" earns it too.
        $this->assertSame(2, $activity->xp_earned);
    }

    public function test_the_rating_must_be_one_of_the_four(): void
    {
        $card = $this->card();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'perfect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating' => 'Pick how well you remembered it: again, hard, good or easy.']);
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rating' => 'Pick how well you remembered it: again, hard, good or easy.']);

        $this->assertSame(0, FlashcardReview::query()->count());
    }

    public function test_someone_elses_card_is_not_found(): void
    {
        $otherLearner = User::factory()->create();
        $theirCard = Flashcard::factory()->for($otherLearner)->for(Topic::factory()->for($otherLearner))->create();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/flashcards/{$theirCard->id}/reviews", ['rating' => 'good'])->assertNotFound();

        $this->assertSame(0, FlashcardReview::query()->count());
        $this->assertSame(0, DailyActivity::query()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(array $attributes = []): Flashcard
    {
        return Flashcard::factory()
            ->for($this->learner)
            ->for($this->topic)
            ->create(['subtopic_id' => $this->subtopic->id, ...$attributes]);
    }
}
