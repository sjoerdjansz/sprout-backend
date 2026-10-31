<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class ExplanationsTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    private const EXPLANATION = 'Plants catch sunlight in their leaves. They use it to make food from water and air.';

    private User $learner;

    private Topic $topic;

    private Subtopic $subtopic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-31 09:00:00');
        $this->learner = $this->learnerWithAiConfigured();
        $this->topic = Topic::factory()->for($this->learner)->create();
        $this->subtopic = Subtopic::factory()->for($this->topic)
            ->withTutorNotes('Plants turn light, water and CO2 into sugar and oxygen in their chloroplasts.')
            ->create(['title' => 'Photosynthesis']);
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_explain_a_subtopic(): void
    {
        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertUnauthorized();
    }

    public function test_a_first_explanation_creates_the_question_it_answers(): void
    {
        Flashcard::factory()->for($this->learner)->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'front' => 'What is made?', 'back' => 'Sugar.']);
        Flashcard::factory()->for($this->learner)->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'suspended_at' => now()]);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->check(70, gaps: [
            ['text' => 'make food from water and air', 'note' => 'What kind of food, and what does the plant give off?'],
        ]))]);
        Sanctum::actingAs($this->learner);

        $response = $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertCreated()
            ->assertJsonPath('question.prompt', "Explain Photosynthesis as if I'm 12.")
            ->assertJsonPath('answer.response', self::EXPLANATION)
            ->assertJsonPath('answer.score', 70)
            ->assertJsonPath('answer.verdict', 'almost')
            ->assertJsonPath('answer.feedback', 'You have the light part right. Name the sugar. Try it once more, simpler.')
            ->assertJsonPath('answer.gaps', [['text' => 'make food from water and air', 'note' => 'What kind of food, and what does the plant give off?']])
            ->assertJsonPath('subtopic.facets.explain', ['percent' => 70, 'holding' => false])
            // Only the explain facet is practised: 70 / 3.
            ->assertJsonPath('subtopic.mastery', 23);

        $question = Question::query()->sole();
        $this->assertSame($response->json('question.id'), $question->id);
        $this->assertSame(QuestionType::Feynman, $question->type);
        $this->assertSame($this->subtopic->id, $question->subtopic_id);
        $this->assertSame($this->topic->id, $question->topic_id);
        $this->assertSame('ai', $question->origin->value);
        $this->assertDatabaseHas('answers', ['question_id' => $question->id, 'user_id' => $this->learner->id, 'score' => 70, 'is_correct' => false]);
        $this->assertDatabaseHas('ai_calls', ['user_id' => $this->learner->id, 'kind' => 'explain_feedback']);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request['response_format']['json_schema']['name'] === 'explanation_check'
                && $request['safety_identifier'] === $this->learner->aiSafetyIdentifier()
                && $input === [
                    'concept' => 'Photosynthesis',
                    'prompt' => "Explain Photosynthesis as if I'm 12.",
                    'key_points' => null,
                    'notes' => "## From the chat\n\nPlants turn light, water and CO2 into sugar and oxygen in their chloroplasts.",
                    // Paused cards are left out, as in the practice session's key points.
                    'flashcards' => [['front' => 'What is made?', 'back' => 'Sugar.']],
                    'first_version' => null,
                    'explanation' => self::EXPLANATION,
                ];
        });

        // The subtopic page shows it like any other answered question.
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")
            ->assertJsonPath('0.type', 'feynman')
            ->assertJsonPath('0.latest_answer.score', 70)
            ->assertJsonPath('0.latest_answer.gaps.0.text', 'make food from water and air');
    }

    public function test_the_subtopics_own_explain_it_question_is_used(): void
    {
        $first = $this->feynmanQuestion(['prompt' => 'Explain photosynthesis to a friend.', 'model_answer' => 'Light, water and CO2 become sugar and oxygen.']);
        $this->feynmanQuestion(['prompt' => 'Explain chlorophyll.']);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->check(90))]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertCreated()
            ->assertJsonPath('question.id', $first->id)
            ->assertJsonPath('question.prompt', 'Explain photosynthesis to a friend.')
            ->assertJsonPath('answer.verdict', 'correct');

        $this->assertSame(2, Question::query()->count());
        $this->assertDatabaseHas('answers', ['question_id' => $first->id, 'score' => 90, 'is_correct' => true]);
        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $input['prompt'] === 'Explain photosynthesis to a friend.'
                && $input['key_points'] === 'Light, water and CO2 become sugar and oxygen.';
        });
    }

    public function test_the_simpler_second_version_gets_feedback_but_does_not_count(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->pushResponse($this->openAiAnswer($this->check(60)))
            ->pushResponse($this->openAiAnswer($this->check(95, feedback: 'Much clearer: you named the sugar now.')))]);
        Sanctum::actingAs($this->learner);
        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])->assertCreated();
        $questionId = Question::query()->sole()->id;

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => 'Leaves use sunlight to make sugar.', 'version' => 2])
            ->assertCreated()
            ->assertJsonPath('question.id', $questionId)
            ->assertJsonPath('answer.response', 'Leaves use sunlight to make sugar.')
            ->assertJsonPath('answer.score', null)
            ->assertJsonPath('answer.verdict', null)
            ->assertJsonPath('answer.feedback', 'Much clearer: you named the sugar now.')
            ->assertJsonPath('subtopic', null);

        $this->assertSame(1, Question::query()->count());
        $this->assertDatabaseHas('answers', ['question_id' => $questionId, 'response' => 'Leaves use sunlight to make sugar.', 'score' => null]);
        // The first version still decides the facet, and only it earned XP.
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}")->assertJsonPath('facets.explain.percent', 60);
        $this->assertSame(10, DailyActivity::query()->sole()->xp_earned);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $input['first_version'] === [
                'explanation' => self::EXPLANATION,
                'feedback' => 'You have the light part right. Name the sugar. Try it once more, simpler.',
            ];
        });
    }

    public function test_a_first_explanation_counts_towards_todays_activity(): void
    {
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->check(30))]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])->assertCreated();

        $activity = DailyActivity::query()->where('user_id', $this->learner->id)->sole();
        $this->assertSame(10, $activity->xp_earned);
        $this->assertSame(0, $activity->questions_answered);
    }

    public function test_gaps_that_are_not_quoted_from_the_explanation_are_left_out(): void
    {
        Http::fake(['api.openai.com/*' => $this->openAiAnswer($this->check(50, gaps: [
            ['text' => 'catch sunlight', 'note' => 'Where exactly?'],
            ['text' => 'chlorophyll does it', 'note' => 'Not in the text.'],
        ]))]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertCreated()
            ->assertJsonPath('answer.gaps', [['text' => 'catch sunlight', 'note' => 'Where exactly?']]);
    }

    public function test_a_second_version_needs_a_first_one(): void
    {
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version' => 'Write your first explanation before a simpler one.']);

        Http::assertNothingSent();
        $this->assertSame(0, Question::query()->count());
    }

    public function test_nothing_is_stored_when_the_ai_cannot_check_the_explanation(): void
    {
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertServiceUnavailable();

        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, DailyActivity::query()->count());
    }

    public function test_an_explanation_and_its_version_are_required(): void
    {
        Http::fake();
        Sanctum::actingAs($this->learner);
        $versionMessage = 'Say which version this is: 1 for your first explanation, 2 for the simpler one.';

        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['explanation' => 'Write your explanation first.', 'version' => $versionMessage]);
        $this->postJson("/api/v1/subtopics/{$this->subtopic->id}/explanations", ['explanation' => str_repeat('a', 5001), 'version' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['explanation' => 'Keep your explanation under 5,000 characters.', 'version' => $versionMessage]);

        Http::assertNothingSent();
    }

    public function test_someone_elses_subtopic_is_not_found(): void
    {
        $theirSubtopic = Subtopic::factory()->for(Topic::factory()->for(User::factory()))->create();
        Http::fake();
        Sanctum::actingAs($this->learner);

        $this->postJson("/api/v1/subtopics/{$theirSubtopic->id}/explanations", ['explanation' => self::EXPLANATION, 'version' => 1])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, Question::query()->count());
    }

    /**
     * @param  array<int, array{text: string, note: string}>  $gaps
     * @return array<string, mixed>
     */
    private function check(int $score, array $gaps = [], string $feedback = 'You have the light part right. Name the sugar. Try it once more, simpler.'): array
    {
        return ['score' => $score, 'feedback' => $feedback, 'gaps' => $gaps];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function feynmanQuestion(array $attributes): Question
    {
        return Question::factory()->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, 'type' => QuestionType::Feynman, ...$attributes]);
    }
}
