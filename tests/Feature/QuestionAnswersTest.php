<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\DailyActivity;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class QuestionAnswersTest extends TestCase
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
        $this->topic = Topic::factory()->for($this->learner)->create();
        $this->subtopic = Subtopic::factory()->for($this->topic)->create();
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_answer_a_question(): void
    {
        [$question] = $this->multipleChoice();

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => 1])->assertUnauthorized();
    }

    public function test_the_right_option_first_time_is_correct(): void
    {
        [$question, $right] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $right->id])
            ->assertCreated()
            ->assertJsonPath('answer.option_id', $right->id)
            ->assertJsonPath('answer.response', null)
            ->assertJsonPath('answer.score', 100)
            ->assertJsonPath('answer.verdict', 'correct')
            ->assertJsonPath('answer.feedback', 'Green is reflected, not absorbed.')
            ->assertJsonPath('try_again', false)
            ->assertJsonPath('subtopic.id', $this->subtopic->id)
            ->assertJsonPath('subtopic.facets.quiz', ['percent' => 100, 'holding' => false])
            // Only the quiz facet is practised: 100 / 3.
            ->assertJsonPath('subtopic.mastery', 33);

        $this->assertDatabaseHas('answers', [
            'question_id' => $question->id,
            'user_id' => $this->learner->id,
            'question_option_id' => $right->id,
            'score' => 100,
            'is_correct' => true,
        ]);
        $this->assertDatabaseHas('subtopic_progress', ['subtopic_id' => $this->subtopic->id, 'mastery' => 33]);
    }

    public function test_a_wrong_first_pick_gets_another_try_and_does_not_count(): void
    {
        [$question, , $wrong] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $wrong->id])
            ->assertCreated()
            ->assertJsonPath('answer.option_id', $wrong->id)
            ->assertJsonPath('answer.score', null)
            ->assertJsonPath('answer.verdict', 'not_quite')
            ->assertJsonPath('answer.feedback', 'Red is absorbed strongly.')
            ->assertJsonPath('try_again', true)
            ->assertJsonPath('subtopic', null);

        // Kept as an attempt, without a score, so it is not the question's latest answer.
        $this->assertDatabaseHas('answers', ['question_option_id' => $wrong->id, 'score' => null, 'is_correct' => false]);
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")->assertJsonPath('0.latest_answer', null);
        $this->assertSame(0, DailyActivity::query()->count());
        $this->assertDatabaseMissing('subtopic_progress', ['subtopic_id' => $this->subtopic->id]);
    }

    public function test_right_on_the_second_try_scores_like_hard(): void
    {
        [$question, $right, $wrong] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $wrong->id])->assertCreated();
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $right->id, 'second_try' => true])
            ->assertCreated()
            ->assertJsonPath('answer.score', 60)
            ->assertJsonPath('answer.verdict', 'almost')
            ->assertJsonPath('try_again', false)
            ->assertJsonPath('subtopic.facets.quiz.percent', 60);

        $this->assertDatabaseHas('answers', ['question_option_id' => $right->id, 'score' => 60, 'is_correct' => false]);
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")
            ->assertJsonPath('0.latest_answer.option_id', $right->id)
            ->assertJsonPath('0.latest_answer.verdict', 'almost');
    }

    public function test_wrong_on_the_second_try_scores_nothing(): void
    {
        [$question, , $wrong] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $wrong->id, 'second_try' => true])
            ->assertCreated()
            ->assertJsonPath('answer.score', 0)
            ->assertJsonPath('answer.verdict', 'not_quite')
            ->assertJsonPath('try_again', false)
            ->assertJsonPath('subtopic.facets.quiz.percent', 0);
    }

    public function test_i_dont_know_ends_the_question_with_no_score(): void
    {
        [$question] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['dont_know' => true])
            ->assertCreated()
            ->assertJsonPath('answer.option_id', null)
            ->assertJsonPath('answer.score', 0)
            ->assertJsonPath('answer.verdict', 'not_quite')
            ->assertJsonPath('answer.feedback', null)
            ->assertJsonPath('try_again', false);
    }

    public function test_answers_count_towards_todays_activity(): void
    {
        [$question, $right, $wrong] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $wrong->id])->assertCreated();
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $right->id, 'second_try' => true])->assertCreated();

        // One question answered, however many tries it took; XP for the effort.
        $activity = DailyActivity::query()->where('user_id', $this->learner->id)->sole();
        $this->assertSame(1, $activity->questions_answered);
        $this->assertSame(0, $activity->cards_reviewed);
        $this->assertSame(2, $activity->xp_earned);
    }

    public function test_an_open_answer_is_checked_by_the_ai(): void
    {
        $question = $this->question(QuestionType::Open, [
            'prompt' => 'Why do plants need light?',
            'model_answer' => 'Light gives the energy to turn water and CO2 into sugar.',
        ]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer([
            'score' => 70,
            'is_correct' => false,
            'feedback' => 'You got that light gives energy. What does the plant make with it? Think about sugar.',
        ])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => 'For energy.'])
            ->assertCreated()
            ->assertJsonPath('answer.response', 'For energy.')
            ->assertJsonPath('answer.option_id', null)
            ->assertJsonPath('answer.score', 70)
            ->assertJsonPath('answer.verdict', 'almost')
            ->assertJsonPath('answer.feedback', 'You got that light gives energy. What does the plant make with it? Think about sugar.')
            ->assertJsonPath('try_again', false)
            ->assertJsonPath('subtopic.facets.quiz.percent', 70);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request->hasHeader('Authorization', 'Bearer '.self::SPROUT_API_KEY)
                && $request['response_format']['json_schema']['name'] === 'answer_check'
                && $request['safety_identifier'] === $this->learner->aiSafetyIdentifier()
                && $input === [
                    'question' => 'Why do plants need light?',
                    'model_answer' => 'Light gives the energy to turn water and CO2 into sugar.',
                    'learner_answer' => 'For energy.',
                ];
        });
        $this->assertDatabaseHas('ai_calls', ['user_id' => $this->learner->id, 'kind' => 'answer_check']);
    }

    public function test_the_ai_score_stays_between_0_and_100(): void
    {
        $question = $this->question(QuestionType::Open);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['score' => 120, 'is_correct' => true, 'feedback' => 'Spot on.'])]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => 'Energy for sugar.'])
            ->assertCreated()
            ->assertJsonPath('answer.score', 100)
            ->assertJsonPath('answer.verdict', 'correct');
    }

    public function test_i_dont_know_on_an_open_question_skips_the_ai(): void
    {
        $question = $this->question(QuestionType::Open);
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['dont_know' => true])
            ->assertCreated()
            ->assertJsonPath('answer.score', 0)
            ->assertJsonPath('answer.verdict', 'not_quite');

        Http::assertNothingSent();
    }

    public function test_nothing_is_stored_when_the_ai_cannot_check_the_answer(): void
    {
        $question = $this->question(QuestionType::Open);
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => 'For energy.'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");

        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, DailyActivity::query()->count());
    }

    public function test_a_multiple_choice_answer_needs_one_of_its_options(): void
    {
        [$question] = $this->multipleChoice();
        [, $otherQuestionsOption] = $this->multipleChoice();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['option_id' => 'Pick an answer first.']);
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => $otherQuestionsOption->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['option_id' => "Pick one of this question's options."]);
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['option_id' => 'green'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['option_id' => "Pick one of this question's options."]);

        $this->assertSame(0, Answer::query()->count());
    }

    public function test_an_open_answer_needs_text(): void
    {
        $question = $this->question(QuestionType::Open);
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['response' => 'Write your answer first.']);
        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => str_repeat('a', 5001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['response' => 'Keep your answer under 5,000 characters.']);

        Http::assertNothingSent();
    }

    public function test_explain_it_questions_are_answered_elsewhere(): void
    {
        $question = $this->question(QuestionType::Feynman);
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$question->id}/answers", ['response' => 'Plants use light to make sugar.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['question' => 'Answer Explain it questions in the Explain it block.']);

        Http::assertNothingSent();
    }

    public function test_someone_elses_question_is_not_found(): void
    {
        $otherLearner = User::factory()->create();
        $theirQuestion = Question::factory()->for(Topic::factory()->for($otherLearner))->create(['type' => QuestionType::MultipleChoice]);
        $theirOption = QuestionOption::factory()->for($theirQuestion)->create(['is_correct' => true]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/questions/{$theirQuestion->id}/answers", ['option_id' => $theirOption->id])->assertNotFound();

        $this->assertSame(0, Answer::query()->count());
    }

    /**
     * A multiple choice question with a right and a wrong option.
     *
     * @return array{0: Question, 1: QuestionOption, 2: QuestionOption}
     */
    private function multipleChoice(): array
    {
        $question = $this->question(QuestionType::MultipleChoice, ['prompt' => 'Which light does chlorophyll absorb least?', 'model_answer' => null]);
        $right = QuestionOption::factory()->for($question)->create(['label' => 'Green', 'is_correct' => true, 'explanation' => 'Green is reflected, not absorbed.', 'position' => 1]);
        $wrong = QuestionOption::factory()->for($question)->create(['label' => 'Red', 'is_correct' => false, 'explanation' => 'Red is absorbed strongly.', 'position' => 2]);

        return [$question, $right, $wrong];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(QuestionType $type, array $attributes = []): Question
    {
        return Question::factory()->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'type' => $type, ...$attributes]);
    }
}
