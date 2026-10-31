<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Enums\ReviewRating;
use App\Enums\SessionKind;
use App\Models\Answer;
use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WeeklyInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $learner;

    private Topic $spanish;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00', 'UTC'));
        $this->learner = User::factory()->create();
        $this->spanish = Topic::factory()->for($this->learner)->create(['title' => 'Spanish']);
    }

    public function test_without_practice_there_is_nothing_to_show_yet(): void
    {
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertOk()
            ->assertExactJson([
                'from' => '2026-10-25',
                'to' => '2026-10-31',
                'streak' => ['current_days' => 0, 'best_days' => 0],
                'recall_rate' => ['percentage' => null, 'previous_percentage' => null, 'change_points' => null],
                'study_time' => ['minutes' => 0, 'topics' => []],
            ]);
    }

    public function test_the_streak_is_the_current_and_the_best_one(): void
    {
        foreach ([0, 1, 5, 6, 7] as $daysAgo) {
            DailyActivity::factory()->for($this->learner)->create(['activity_date' => today()->subDays($daysAgo)]);
        }
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('streak', ['current_days' => 2, 'best_days' => 3]);
    }

    public function test_the_recall_rate_counts_the_first_try_of_every_card_and_quiz_question_on_a_day(): void
    {
        $card = $this->card();
        // Missed first, then right 10 minutes later: only the miss counts that day.
        $this->review($card, ReviewRating::Again, '2026-10-30 09:00');
        $this->review($card, ReviewRating::Good, '2026-10-30 09:10');
        // The next day it counts again.
        $this->review($card, ReviewRating::Hard, '2026-10-31 09:00');
        $this->review($this->card(), ReviewRating::Easy, '2026-10-29 09:00');

        $question = Question::factory()->for($this->spanish)->create(['type' => QuestionType::MultipleChoice]);
        // A wrong first pick has no score; right on the second try (60, not correct) isn't recall.
        $this->answer($question, null, false, '2026-10-30 10:00');
        $this->answer($question, 60, false, '2026-10-30 10:01');
        $this->answer(Question::factory()->for($this->spanish)->create(), 90, true, '2026-10-30 11:00');
        // Explanations aren't recall.
        $this->answer(Question::factory()->for($this->spanish)->create(['type' => QuestionType::Feynman]), 20, false, '2026-10-30 12:00');

        // Someone else's practice doesn't count.
        FlashcardReview::factory()->create(['rating' => ReviewRating::Again, 'reviewed_at' => '2026-10-30 09:00']);
        Answer::factory()->create(['score' => 0, 'is_correct' => false, 'answered_at' => '2026-10-30 09:00']);
        Sanctum::actingAs($this->learner);

        // Remembered: hard, easy, the open answer. Not: the miss and the second try. 3 of 5.
        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('recall_rate', ['percentage' => 60, 'previous_percentage' => null, 'change_points' => null]);
    }

    public function test_the_recall_rate_is_compared_with_the_7_days_before(): void
    {
        // This week (25 to 31 October): 3 of 4 remembered.
        $this->review($this->card(), ReviewRating::Good, '2026-10-25 08:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-27 08:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-31 08:00');
        $this->review($this->card(), ReviewRating::Again, '2026-10-31 08:00');
        // The week before (18 to 24 October): 1 of 2.
        $this->review($this->card(), ReviewRating::Good, '2026-10-18 08:00');
        $this->review($this->card(), ReviewRating::Again, '2026-10-24 23:00');
        // Too long ago.
        $this->review($this->card(), ReviewRating::Again, '2026-10-17 08:00');
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('recall_rate', ['percentage' => 75, 'previous_percentage' => 50, 'change_points' => 25]);
    }

    public function test_the_week_follows_the_learners_timezone(): void
    {
        UserSetting::factory()->for($this->learner)->create(['timezone' => 'Pacific/Auckland']);
        // It is 1 November, 01:00 in Auckland, so the week runs from 26 October to 1 November there.
        // 25 October 10:00 UTC is 23:00 that day in Auckland, before the week; 12:00 UTC is 26 October.
        $this->review($this->card(), ReviewRating::Again, '2026-10-25 10:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-25 12:00');
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('from', '2026-10-26')
            ->assertJsonPath('to', '2026-11-01')
            ->assertJsonPath('recall_rate.percentage', 100)
            ->assertJsonPath('recall_rate.previous_percentage', 0);
    }

    public function test_study_time_is_the_active_time_in_practice_sessions_per_topic(): void
    {
        $biology = Topic::factory()->for($this->learner)->create(['title' => 'Biology']);

        // 09:00 start, card at 09:04, card at 09:10, then a lunch break, answer at 12:00, answer at 12:02:
        // 4 + 6 + 2 minutes; the break doesn't count.
        $spanishSession = $this->practiceSession($this->spanish, '2026-10-30 09:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-30 09:04', $spanishSession);
        $this->review($this->card(), ReviewRating::Good, '2026-10-30 09:10', $spanishSession);
        $this->answer(Question::factory()->for($this->spanish)->create(), 90, true, '2026-10-30 12:00', $spanishSession);
        $this->answer(Question::factory()->for($this->spanish)->create(), 90, true, '2026-10-30 12:02', $spanishSession);

        // An open session on Biology: 3 minutes to the first message, 5 to the reply.
        $biologySession = $this->practiceSession($biology, '2026-10-31 08:00');
        Message::factory()->for($biologySession)->create(['created_at' => '2026-10-31 08:03']);
        Message::factory()->for($biologySession)->create(['created_at' => '2026-10-31 08:08']);

        // A session before the week, and a conversation with the tutor, don't count.
        $earlier = $this->practiceSession($this->spanish, '2026-10-24 09:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-24 09:05', $earlier);
        $conversation = StudySession::factory()->for($this->learner)->for($biology)->create(['started_at' => '2026-10-31 07:00']);
        Message::factory()->for($conversation)->create(['created_at' => '2026-10-31 07:10']);
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('study_time', [
                'minutes' => 20,
                'topics' => [
                    ['id' => $this->spanish->id, 'title' => 'Spanish', 'minutes' => 12],
                    ['id' => $biology->id, 'title' => 'Biology', 'minutes' => 8],
                ],
            ]);
    }

    public function test_a_session_across_topics_splits_its_time_by_the_topic_of_each_card(): void
    {
        $biology = Topic::factory()->for($this->learner)->create(['title' => 'Biology']);
        $session = $this->practiceSession(null, '2026-10-31 09:00');
        $this->review($this->card(), ReviewRating::Good, '2026-10-31 09:01', $session);
        $this->review($this->card($biology), ReviewRating::Good, '2026-10-31 09:05', $session);
        $this->review($this->card(), ReviewRating::Good, '2026-10-31 09:06', $session);
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/insights/week')
            ->assertJsonPath('study_time.minutes', 6)
            ->assertJsonPath('study_time.topics', [
                ['id' => $biology->id, 'title' => 'Biology', 'minutes' => 4],
                ['id' => $this->spanish->id, 'title' => 'Spanish', 'minutes' => 2],
            ]);
    }

    public function test_insights_need_a_token(): void
    {
        $this->getJson('/api/v1/insights/week')->assertUnauthorized();
    }

    private function card(?Topic $topic = null): Flashcard
    {
        return Flashcard::factory()->for($this->learner)->for($topic ?? $this->spanish)->create();
    }

    private function review(Flashcard $card, ReviewRating $rating, string $at, ?StudySession $session = null): void
    {
        FlashcardReview::factory()->for($card)->create([
            'rating' => $rating,
            'reviewed_at' => $at,
            'study_session_id' => $session?->id,
        ]);
    }

    private function answer(Question $question, ?int $score, bool $isCorrect, string $at, ?StudySession $session = null): void
    {
        Answer::factory()->for($question)->for($this->learner)->create([
            'score' => $score,
            'is_correct' => $isCorrect,
            'answered_at' => $at,
            'study_session_id' => $session?->id,
        ]);
    }

    private function practiceSession(?Topic $topic, string $startedAt): StudySession
    {
        return StudySession::factory()->for($this->learner)->create([
            'topic_id' => $topic?->id,
            'kind' => SessionKind::Review,
            'started_at' => $startedAt,
            'ended_at' => null,
        ]);
    }
}
