<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Enums\SessionKind;
use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class PracticeSessionsTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    private const SESSION_ENDED = 'This practice session has ended. Start a new one to keep practising.';

    private User $learner;

    private Topic $topic;

    private Subtopic $light;

    private Subtopic $water;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-31 09:00:00');
        $this->learner = $this->learnerWithAiConfigured();
        $this->topic = Topic::factory()->for($this->learner)->create(['title' => 'Photosynthesis']);
        $this->light = Subtopic::factory()->for($this->topic)->create(['title' => 'Light', 'position' => 1]);
        $this->water = Subtopic::factory()->for($this->topic)->create(['title' => 'Water', 'position' => 2]);
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_use_practice_sessions(): void
    {
        $this->postJson('/api/v1/practice-sessions', [])->assertUnauthorized();
        $this->getJson('/api/v1/practice-sessions/current')->assertUnauthorized();
        $this->postJson('/api/v1/practice-sessions/1/finish')->assertUnauthorized();
        $this->getJson("/api/v1/subtopics/{$this->light->id}/practice-sessions")->assertUnauthorized();
    }

    public function test_starting_a_session_keeps_its_plan_and_the_mastery_before(): void
    {
        SubtopicProgress::factory()->for($this->learner)->for($this->light)->create(['mastery' => 40]);
        $card = $this->card($this->light);
        $question = $this->multipleChoice($this->water)[0];
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', [
            'topic_id' => $this->topic->id,
            'modes' => ['cards', 'retrieval', 'feynman'],
            'card_ids' => [$card->id],
            'question_ids' => [$question->id],
            'explain_subtopic_id' => $this->water->id,
        ])
            ->assertCreated()
            ->assertJsonPath('scope', 'topic')
            ->assertJsonPath('topic', ['id' => $this->topic->id, 'title' => 'Photosynthesis'])
            ->assertJsonPath('subtopic', null)
            ->assertJsonPath('plan', [
                'modes' => ['cards', 'retrieval', 'feynman'],
                'card_ids' => [$card->id],
                'question_ids' => [$question->id],
                'explain_subtopic_id' => $this->water->id,
            ])
            ->assertJsonPath('started_at', '2026-10-31T09:00:00.000000Z')
            ->assertJsonPath('ended_at', null)
            ->assertJsonPath('is_finished', false)
            ->assertJsonPath('done', ['flashcard_reviews' => [], 'answers' => [], 'explanations' => [], 'messages' => [], 'message_count' => 0])
            ->assertJsonPath('xp_earned', 0)
            ->assertJsonPath('subtopics', []);

        $session = StudySession::query()->sole();
        $this->assertSame(SessionKind::Review, $session->kind);
        $this->assertSame([$this->light->id => 40, $this->water->id => 0], $session->mastery_before);
    }

    public function test_a_session_on_one_subtopic_shows_it_from_the_start(): void
    {
        SubtopicProgress::factory()->for($this->learner)->for($this->light)->create(['mastery' => 40]);
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', $this->plan(['subtopic_id' => $this->light->id, 'modes' => ['open']]))
            ->assertCreated()
            ->assertJsonPath('subtopic', ['id' => $this->light->id, 'title' => 'Light'])
            ->assertJsonPath('scope', 'subtopic')
            ->assertJsonPath('subtopics', [[
                'id' => $this->light->id,
                'title' => 'Light',
                'topic' => ['id' => $this->topic->id, 'title' => 'Photosynthesis'],
                'mastery_before' => 40,
                'mastery_after' => 40,
                'next_review_on' => null,
            ]]);
    }

    public function test_what_was_practised_in_the_session_is_counted_from_its_ratings_and_answers(): void
    {
        $card = $this->card($this->light);
        [$question, $right, $wrong] = $this->multipleChoice($this->water);
        $this->card($this->light, ['subtopic_id' => $this->light->id]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['score' => 60, 'feedback' => 'Good start.', 'gaps' => []])]);
        Sanctum::actingAs($this->learner);
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->plan(['modes' => ['cards', 'retrieval', 'feynman']]))->json('id');

        $this->travel(1)->minutes();
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'again', 'practice_session_id' => $sessionId])->assertCreated();
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $wrong->id, 'practice_session_id' => $sessionId])->assertCreated();
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $right->id, 'second_try' => true, 'practice_session_id' => $sessionId])->assertCreated();
        $this->travel(5)->minutes();
        $this->postJson("/api/v1/subtopics/{$this->water->id}/explanations", ['explanation' => 'Roots take it up.', 'version' => 1, 'practice_session_id' => $sessionId])->assertCreated();
        // Practice outside the session doesn't count for it.
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'easy'])->assertCreated();

        $explainQuestionId = Question::query()->where('type', QuestionType::Feynman)->sole()->id;

        $this->getJson('/api/v1/practice-sessions/current')
            ->assertOk()
            ->assertJsonPath('id', $sessionId)
            ->assertJsonPath('done.flashcard_reviews.0.flashcard_id', $card->id)
            ->assertJsonPath('done.flashcard_reviews.0.rating', 'again')
            ->assertJsonPath('done.flashcard_reviews.1.rating', 'good')
            ->assertJsonCount(2, 'done.flashcard_reviews')
            ->assertJsonPath('done.answers.0', [
                'question_id' => $question->id,
                'option_id' => $wrong->id,
                'score' => null,
                'verdict' => 'not_quite',
                'answered_at' => '2026-10-31T09:01:00.000000Z',
            ])
            ->assertJsonPath('done.answers.1.score', 60)
            ->assertJsonPath('done.answers.1.verdict', 'almost')
            ->assertJsonPath('done.explanations', [[
                'question_id' => $explainQuestionId,
                'version' => 1,
                'score' => 60,
                'verdict' => 'almost',
                'answered_at' => '2026-10-31T09:06:00.000000Z',
            ]])
            ->assertJsonPath('done.message_count', 0)
            // 2 cards × 1 + 1 graded answer × 2 + 1 explanation × 10; the wrong first pick earns nothing.
            ->assertJsonPath('xp_earned', 14)
            ->assertJsonPath('last_activity_at', '2026-10-31T09:06:00.000000Z')
            ->assertJsonPath('subtopics.0.id', $this->light->id)
            ->assertJsonPath('subtopics.0.mastery_before', 0)
            ->assertJsonPath('subtopics.1.id', $this->water->id)
            // Quiz 60 and explain 60, cards not practised: 120 / 3.
            ->assertJsonPath('subtopics.1.mastery_after', 40);
    }

    public function test_there_is_nothing_to_resume_without_a_session_from_today(): void
    {
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/practice-sessions/current')->assertNoContent();

        $this->practiceSession(['started_at' => now()->subDay()]);

        $this->getJson('/api/v1/practice-sessions/current')->assertNoContent();
    }

    public function test_today_follows_the_learners_timezone_for_resuming(): void
    {
        // 00:30 in Amsterdam on 1 November; the session started at 23:50 there on 31 October.
        $this->travelTo('2026-10-31 23:30:00');
        UserSetting::query()->where('user_id', $this->learner->id)->update(['timezone' => 'Europe/Amsterdam']);
        $this->practiceSession(['started_at' => now()->subMinutes(40)]);
        Sanctum::actingAs($this->learner);

        $this->getJson('/api/v1/practice-sessions/current')->assertNoContent();
    }

    public function test_finishing_a_session_keeps_the_mastery_it_ended_on(): void
    {
        $card = $this->card($this->light);
        Sanctum::actingAs($this->learner);
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->plan(['subtopic_id' => $this->light->id]))->json('id');
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();

        $this->travel(10)->minutes();
        $this->postJson("/api/v1/practice-sessions/{$sessionId}/finish")
            ->assertOk()
            ->assertJsonPath('ended_at', '2026-10-31T09:10:00.000000Z')
            ->assertJsonPath('is_finished', true)
            ->assertJsonPath('subtopics.0.mastery_before', 0)
            ->assertJsonPath('subtopics.0.mastery_after', 28)
            ->assertJsonPath('subtopics.0.next_review_on', '2026-11-01');

        // Later practice changes the subtopic, not what this session achieved.
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'again'])->assertCreated();
        $this->getJson("/api/v1/subtopics/{$this->light->id}/practice-sessions")
            ->assertJsonPath('0.subtopics.0.mastery_after', 28);
        $this->getJson('/api/v1/practice-sessions/current')->assertNoContent();

        // Finishing again changes nothing.
        $this->travel(10)->minutes();
        $this->postJson("/api/v1/practice-sessions/{$sessionId}/finish")->assertOk()->assertJsonPath('ended_at', '2026-10-31T09:10:00.000000Z');
    }

    public function test_finishing_a_session_without_practice_deletes_it(): void
    {
        Sanctum::actingAs($this->learner);
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');

        $this->postJson("/api/v1/practice-sessions/{$sessionId}/finish")->assertNoContent();

        $this->assertSame(0, StudySession::query()->count());
    }

    public function test_starting_a_session_finishes_the_open_one(): void
    {
        $card = $this->card($this->light);
        Sanctum::actingAs($this->learner);
        $practisedId = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $practisedId])->assertCreated();
        $emptyId = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');

        $newId = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');

        $this->assertNotNull(StudySession::query()->find($practisedId)->ended_at);
        $this->assertNull(StudySession::query()->find($emptyId));
        $this->getJson('/api/v1/practice-sessions/current')->assertJsonPath('id', $newId);
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $practisedId])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['practice_session_id' => self::SESSION_ENDED]);
    }

    public function test_practice_can_only_be_added_to_your_own_open_session(): void
    {
        $card = $this->card($this->light);
        [$question, $right] = $this->multipleChoice($this->light);
        $someoneElses = StudySession::factory()->create(['kind' => SessionKind::Review, 'ended_at' => null]);
        $conversation = StudySession::factory()->for($this->learner)->create(['kind' => SessionKind::Learn, 'ended_at' => null]);
        Http::fake();
        Sanctum::actingAs($this->learner);

        foreach ([$someoneElses->id, $conversation->id, 999] as $sessionId) {
            $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['practice_session_id' => self::SESSION_ENDED]);
        }
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $right->id, 'practice_session_id' => $someoneElses->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['practice_session_id' => self::SESSION_ENDED]);
        $this->postJson("/api/v1/subtopics/{$this->light->id}/explanations", ['explanation' => 'Light is energy.', 'version' => 1, 'practice_session_id' => $someoneElses->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['practice_session_id' => self::SESSION_ENDED]);

        Http::assertNothingSent();
        $this->assertSame(0, $card->reviews()->count());
    }

    public function test_the_plan_must_fit_what_you_practise(): void
    {
        $otherTopic = Topic::factory()->for($this->learner)->create();
        $otherSubtopic = Subtopic::factory()->for($otherTopic)->create();
        $cardElsewhere = $this->card($this->water);
        $explainQuestion = Question::factory()->for($this->topic)->create(['subtopic_id' => $this->light->id, 'type' => QuestionType::Feynman]);
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', $this->plan([
            'subtopic_id' => $this->light->id,
            'card_ids' => [$cardElsewhere->id],
            'question_ids' => [$explainQuestion->id],
            'explain_subtopic_id' => $this->water->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'card_ids' => "Pick cards from what you're practising.",
                'question_ids' => "Pick quiz questions from what you're practising.",
                'explain_subtopic_id' => "Pick a subtopic from what you're practising to explain.",
            ]);

        $this->postJson('/api/v1/practice-sessions', $this->plan(['subtopic_id' => $otherSubtopic->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtopic_id' => 'Pick a subtopic of this topic.']);

        $this->assertSame(0, StudySession::query()->count());
    }

    public function test_a_session_needs_one_of_your_topics_and_a_way_to_practise(): void
    {
        $someoneElsesTopic = Topic::factory()->create();
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/practice-sessions', ['topic_id' => $someoneElsesTopic->id, 'modes' => [], 'card_ids' => [], 'question_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'topic_id' => 'Pick one of your topics to practise.',
                'modes' => 'Pick at least one way to practise.',
            ]);
        $this->postJson('/api/v1/practice-sessions', ['topic_id' => $this->topic->id, 'modes' => ['cards', 'cards', 'singing']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'modes.0' => 'Pick each way to practise only once.',
                'modes.2' => 'Pick a way to practise: cards, retrieval, feynman or open.',
                'card_ids' => 'Send the cards for this session, or an empty list.',
                'question_ids' => 'Send the questions for this session, or an empty list.',
            ]);

        $this->assertSame(0, StudySession::query()->count());
    }

    public function test_someone_elses_session_cannot_be_finished(): void
    {
        $someoneElses = StudySession::factory()->create(['kind' => SessionKind::Review, 'ended_at' => null]);
        $conversation = StudySession::factory()->for($this->learner)->create(['kind' => SessionKind::Learn, 'ended_at' => null]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/practice-sessions/{$someoneElses->id}/finish")->assertNotFound();
        $this->postJson("/api/v1/practice-sessions/{$conversation->id}/finish")->assertNotFound();

        $this->assertNull($someoneElses->refresh()->ended_at);
    }

    public function test_the_sessions_tab_lists_the_sessions_in_which_the_subtopic_was_practised(): void
    {
        $lightCard = $this->card($this->light);
        $waterCard = $this->card($this->water);
        Sanctum::actingAs($this->learner);

        $topicSession = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');
        $this->postJson("/api/v1/flashcards/{$lightCard->id}/reviews", ['rating' => 'good', 'practice_session_id' => $topicSession])->assertCreated();
        $this->travel(1)->hours();
        $waterSession = $this->postJson('/api/v1/practice-sessions', $this->plan())->json('id');
        $this->postJson("/api/v1/flashcards/{$waterCard->id}/reviews", ['rating' => 'good', 'practice_session_id' => $waterSession])->assertCreated();
        $this->travel(1)->hours();
        // About Light, but nothing practised yet: not listed.
        $this->postJson('/api/v1/practice-sessions', $this->plan(['subtopic_id' => $this->light->id]))->assertCreated();

        $this->getJson("/api/v1/subtopics/{$this->light->id}/practice-sessions")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $topicSession);
        $this->getJson("/api/v1/subtopics/{$this->water->id}/practice-sessions")
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $waterSession);
    }

    public function test_someone_elses_subtopic_has_no_sessions_to_show(): void
    {
        $theirSubtopic = Subtopic::factory()->for(Topic::factory())->create();
        Sanctum::actingAs($this->learner);

        $this->getJson("/api/v1/subtopics/{$theirSubtopic->id}/practice-sessions")->assertNotFound();
    }

    public function test_the_streak_counts_the_days_in_a_row_and_lasts_until_today_is_over(): void
    {
        foreach ([1, 2, 4] as $daysAgo) {
            DailyActivity::factory()->for($this->learner)->create(['activity_date' => today()->subDays($daysAgo)]);
        }
        Sanctum::actingAs($this->learner);

        // Nothing yet today: yesterday's streak of 2 still counts.
        $sessionId = $this->postJson('/api/v1/practice-sessions', $this->plan())->assertJsonPath('streak_days', 2)->json('id');

        $card = $this->card($this->light);
        $this->postJson("/api/v1/flashcards/{$card->id}/reviews", ['rating' => 'good', 'practice_session_id' => $sessionId])->assertCreated();

        $this->getJson('/api/v1/practice-sessions/current')->assertJsonPath('streak_days', 3);
    }

    public function test_resetting_progress_removes_practice_sessions_but_not_conversations(): void
    {
        $this->practiceSession();
        $conversation = StudySession::factory()->for($this->learner)->create(['kind' => SessionKind::Learn]);
        Sanctum::actingAs($this->learner);

        $this->postJson('/api/v1/user/progress/reset')->assertNoContent();

        $this->assertSame([$conversation->id], StudySession::query()->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function plan(array $overrides = []): array
    {
        return ['topic_id' => $this->topic->id, 'modes' => ['cards'], 'card_ids' => [], 'question_ids' => [], ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function practiceSession(array $attributes = []): StudySession
    {
        return StudySession::factory()->for($this->learner)->for($this->topic)->create([
            'kind' => SessionKind::Review,
            'plan' => ['modes' => ['cards'], 'card_ids' => [], 'question_ids' => [], 'explain_subtopic_id' => null],
            'started_at' => now(),
            'ended_at' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function card(Subtopic $subtopic, array $attributes = []): Flashcard
    {
        return Flashcard::factory()->for($this->learner)->for($this->topic)->create(['subtopic_id' => $subtopic->id, ...$attributes]);
    }

    /**
     * @return array{0: Question, 1: QuestionOption, 2: QuestionOption}
     */
    private function multipleChoice(Subtopic $subtopic): array
    {
        $question = Question::factory()->for($this->topic)->create(['subtopic_id' => $subtopic->id, 'type' => QuestionType::MultipleChoice, 'model_answer' => null]);
        $right = QuestionOption::factory()->for($question)->create(['is_correct' => true, 'explanation' => 'Right.', 'position' => 1]);
        $wrong = QuestionOption::factory()->for($question)->create(['is_correct' => false, 'explanation' => 'Wrong.', 'position' => 2]);

        return [$question, $right, $wrong];
    }
}
