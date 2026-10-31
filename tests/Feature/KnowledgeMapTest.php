<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Enums\ReviewRating;
use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KnowledgeMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_library_lists_your_topics_with_their_subtopics_in_order(): void
    {
        $learner = $this->learner();
        $topic = Topic::factory()->for($learner)->create([
            'title' => 'Photosynthesis',
            'description' => 'How plants make sugar.',
            'notes' => 'From my biology book.',
            'goal' => 'Explain both stages',
            'goal_deadline' => '2026-12-01',
            'goal_recall_percentage' => 85,
        ]);
        Subtopic::factory()->for($topic)->create(['title' => 'Calvin cycle', 'position' => 2]);
        $first = Subtopic::factory()->for($topic)->create(['title' => 'Light', 'description' => 'What light does.', 'content' => 'Red and blue.', 'position' => 1]);
        Topic::factory()->for($learner)->archived()->create();
        Topic::factory()->create();
        Sanctum::actingAs($learner);

        $response = $this->getJson('/api/v1/topics');

        $response->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $topic->id)
            ->assertJsonPath('0.title', 'Photosynthesis')
            ->assertJsonPath('0.description', 'How plants make sugar.')
            ->assertJsonPath('0.notes', 'From my biology book.')
            ->assertJsonPath('0.category', ['id' => $topic->category_id, 'name' => $topic->category->name])
            ->assertJsonPath('0.goal', ['description' => 'Explain both stages', 'deadline' => '2026-12-01', 'recall_percentage' => 85])
            ->assertJsonPath('0.subtopics.0', [
                'id' => $first->id,
                'topic_id' => $topic->id,
                'title' => 'Light',
                'description' => 'What light does.',
                'notes' => 'Red and blue.',
                'generation_status' => 'ready',
                'mastery' => 0,
                'due_cards' => 0,
                'next_review_on' => null,
                'is_new' => true,
                'slipping' => false,
                'facets' => [
                    'cards' => ['percent' => null, 'holding' => false],
                    'explain' => ['percent' => null, 'holding' => false],
                    'quiz' => ['percent' => null, 'holding' => false],
                ],
            ])
            ->assertJsonPath('0.subtopics.1.title', 'Calvin cycle');
    }

    public function test_a_facet_holds_only_after_two_spaced_reviews_at_85_percent_or_more(): void
    {
        $this->freezeTime();
        $learner = $this->learner();
        [$holding, $oneGoodDay, $dropped] = $this->subtopics($learner, 3);

        // Two days in a row of "good" (85) holds; the facet's percentage is the last day's average.
        $this->reviewCard($learner, $holding, [[3, ReviewRating::Good], [1, ReviewRating::Easy], [1, ReviewRating::Good]]);
        // Two good reviews on the same day are one session, not two spaced reviews.
        $this->reviewCard($learner, $oneGoodDay, [[0, ReviewRating::Easy], [0, ReviewRating::Easy]]);
        // A weak last review breaks the streak.
        $this->reviewCard($learner, $dropped, [[5, ReviewRating::Easy], [3, ReviewRating::Easy], [1, ReviewRating::Hard]]);
        Sanctum::actingAs($learner);

        $subtopics = $this->getJson('/api/v1/topics')->assertOk()->json('0.subtopics');

        $this->assertSame(['percent' => 93, 'holding' => true], $subtopics[0]['facets']['cards']);
        $this->assertSame(['percent' => 100, 'holding' => false], $subtopics[1]['facets']['cards']);
        $this->assertSame(['percent' => 60, 'holding' => false], $subtopics[2]['facets']['cards']);
        $this->assertFalse($subtopics[0]['is_new']);
    }

    public function test_quiz_and_explain_come_from_your_graded_answers(): void
    {
        $this->freezeTime();
        $learner = $this->learner();
        [$subtopic] = $this->subtopics($learner, 1);
        $multipleChoice = Question::factory()->for($subtopic->topic)->for($subtopic)->create(['type' => QuestionType::MultipleChoice]);
        $open = Question::factory()->for($subtopic->topic)->for($subtopic)->create(['type' => QuestionType::Open]);
        $feynman = Question::factory()->for($subtopic->topic)->for($subtopic)->create(['type' => QuestionType::Feynman]);
        $this->answer($learner, $multipleChoice, 100, daysAgo: 4);
        $this->answer($learner, $open, 90, daysAgo: 2);
        $this->answer($learner, $feynman, 70, daysAgo: 1);
        $this->answer($learner, $feynman, null, daysAgo: 0);
        $this->answer(User::factory()->create(), $feynman, 10, daysAgo: 0);
        Sanctum::actingAs($learner);

        $facets = $this->getJson('/api/v1/topics')->assertOk()->json('0.subtopics.0.facets');

        $this->assertSame(['percent' => 90, 'holding' => true], $facets['quiz']);
        $this->assertSame(['percent' => 70, 'holding' => false], $facets['explain']);
        $this->assertSame(['percent' => null, 'holding' => false], $facets['cards']);
    }

    public function test_due_cards_and_the_next_review_follow_your_timezone(): void
    {
        // 23:30 in Amsterdam on 30 October.
        $this->travelTo(Carbon::parse('2026-10-30 22:30:00', 'UTC'));
        $learner = $this->learner('Europe/Amsterdam');
        [$dueToday, $dueTomorrow] = $this->subtopics($learner, 2);

        $this->reviewCard($learner, $dueToday, [[2, ReviewRating::Good]], dueAt: '2026-10-29 08:00:00');
        $this->reviewCard($learner, $dueToday, [[2, ReviewRating::Good]], dueAt: '2026-10-30 22:45:00');
        $this->reviewCard($learner, $dueToday, [[2, ReviewRating::Good]], dueAt: '2026-10-29 08:00:00', suspended: true);
        // 00:30 on 31 October in Amsterdam, though still the 30th in UTC.
        $this->reviewCard($learner, $dueTomorrow, [[2, ReviewRating::Good]], dueAt: '2026-10-30 23:30:00');
        Sanctum::actingAs($learner);

        $subtopics = $this->getJson('/api/v1/topics')->assertOk()->json('0.subtopics');

        $this->assertSame(2, $subtopics[0]['due_cards']);
        $this->assertSame('2026-10-30', $subtopics[0]['next_review_on']);
        $this->assertSame(0, $subtopics[1]['due_cards']);
        $this->assertSame('2026-10-31', $subtopics[1]['next_review_on']);
    }

    public function test_a_subtopic_slips_only_when_recall_drops_after_it_was_known(): void
    {
        $this->freezeTime();
        $learner = $this->learner();
        [$slipping, $neverKnown, $fresh] = $this->subtopics($learner, 3);

        // Reviewed 20 days ago with a 2-day interval: expected recall is 0.9^10, about 35%.
        foreach ([$slipping, $neverKnown] as $subtopic) {
            $this->reviewCard($learner, $subtopic, [[20, ReviewRating::Good]], intervalDays: 2);
        }
        $this->reviewCard($learner, $fresh, [[1, ReviewRating::Good]], intervalDays: 2);
        SubtopicProgress::factory()->for($learner)->for($slipping)->create(['mastery' => 50, 'peak_mastery' => 70]);
        SubtopicProgress::factory()->for($learner)->for($neverKnown)->create(['mastery' => 50, 'peak_mastery' => 50]);
        SubtopicProgress::factory()->for($learner)->for($fresh)->create(['mastery' => 70, 'peak_mastery' => 70]);
        Sanctum::actingAs($learner);

        $subtopics = $this->getJson('/api/v1/topics')->assertOk()->json('0.subtopics');

        $this->assertTrue($subtopics[0]['slipping']);
        $this->assertSame(50, $subtopics[0]['mastery']);
        $this->assertFalse($subtopics[1]['slipping']);
        $this->assertFalse($subtopics[2]['slipping']);
    }

    public function test_the_library_needs_a_token(): void
    {
        $this->getJson('/api/v1/topics')->assertUnauthorized();
    }

    private function learner(string $timezone = 'UTC'): User
    {
        $learner = User::factory()->create();
        UserSetting::factory()->for($learner)->create(['timezone' => $timezone]);

        return $learner;
    }

    /**
     * @return array<int, Subtopic> subtopics of one topic, in position order
     */
    private function subtopics(User $learner, int $count): array
    {
        $topic = Topic::factory()->for($learner)->create();

        return array_map(
            fn (int $position): Subtopic => Subtopic::factory()->for($topic)->create(['position' => $position]),
            range(1, $count),
        );
    }

    /**
     * @param  array<int, array{0: int, 1: ReviewRating}>  $reviews  [days ago, rating]
     */
    private function reviewCard(
        User $learner,
        Subtopic $subtopic,
        array $reviews,
        ?string $dueAt = null,
        int $intervalDays = 3,
        bool $suspended = false,
    ): void {
        $flashcard = Flashcard::factory()->for($learner)->for($subtopic->topic)->for($subtopic)->create([
            'due_at' => $dueAt === null ? now()->addDays(30) : Carbon::parse($dueAt, 'UTC'),
            'interval_days' => $intervalDays,
            'suspended_at' => $suspended ? now() : null,
        ]);

        foreach ($reviews as [$daysAgo, $rating]) {
            FlashcardReview::factory()->for($flashcard)->create(['rating' => $rating, 'reviewed_at' => now()->subDays($daysAgo)]);
        }
    }

    private function answer(User $learner, Question $question, ?int $score, int $daysAgo): void
    {
        Answer::factory()->for($learner)->for($question)->create(['score' => $score, 'answered_at' => now()->subDays($daysAgo)]);
    }
}
