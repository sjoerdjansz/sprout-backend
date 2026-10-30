<?php

namespace Tests\Feature;

use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuestionsTest extends TestCase
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

    public function test_guest_cannot_list_questions(): void
    {
        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")->assertUnauthorized();
    }

    public function test_a_multiple_choice_question_shows_its_options_and_your_latest_graded_answer(): void
    {
        $question = $this->question(['type' => QuestionType::MultipleChoice, 'prompt' => 'Which light does chlorophyll absorb least?']);
        $green = QuestionOption::factory()->for($question)->create(['label' => 'Green', 'is_correct' => true, 'position' => 2]);
        $red = QuestionOption::factory()->for($question)->create(['label' => 'Red', 'is_correct' => false, 'position' => 1]);
        $this->answer($question, ['question_option_id' => $red->id, 'is_correct' => false, 'score' => 0, 'answered_at' => now()->subDays(2)]);
        $this->answer($question, ['question_option_id' => $green->id, 'is_correct' => true, 'score' => 100, 'feedback' => 'Exactly.', 'answered_at' => now()->subDay()]);
        // Not graded yet, and someone else's answer: neither counts.
        $this->answer($question, ['question_option_id' => $red->id, 'score' => null, 'is_correct' => null, 'answered_at' => now()]);
        Answer::factory()->for($question)->create(['question_option_id' => $red->id, 'is_correct' => false, 'score' => 0, 'answered_at' => now()]);
        Sanctum::actingAs($this->learner);

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'multiple_choice')
            ->assertJsonPath('0.prompt', 'Which light does chlorophyll absorb least?')
            ->assertJsonPath('0.options.0', ['id' => $red->id, 'label' => 'Red', 'is_correct' => false, 'explanation' => null])
            ->assertJsonPath('0.options.1.label', 'Green')
            ->assertJsonPath('0.latest_answer.option_id', $green->id)
            ->assertJsonPath('0.latest_answer.verdict', 'correct')
            ->assertJsonPath('0.latest_answer.feedback', 'Exactly.');
    }

    public function test_the_verdict_is_almost_from_half_marks_and_unanswered_questions_are_listed_too(): void
    {
        $almost = $this->question(['type' => QuestionType::Open]);
        $this->answer($almost, ['response' => 'Light gives energy.', 'is_correct' => false, 'score' => 50]);
        $notQuite = $this->question(['type' => QuestionType::Feynman]);
        $this->answer($notQuite, ['is_correct' => false, 'score' => 49]);
        $unanswered = $this->question();
        $this->question(['subtopic_id' => Subtopic::factory()->for($this->topic)]);
        Sanctum::actingAs($this->learner);

        $this->getJson("/api/v1/subtopics/{$this->subtopic->id}/questions")
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.latest_answer.response', 'Light gives energy.')
            ->assertJsonPath('0.latest_answer.verdict', 'almost')
            ->assertJsonPath('0.options', [])
            ->assertJsonPath('1.type', 'feynman')
            ->assertJsonPath('1.latest_answer.verdict', 'not_quite')
            ->assertJsonPath('2.id', $unanswered->id)
            ->assertJsonPath('2.latest_answer', null);
    }

    public function test_deleting_a_question_removes_its_options_and_answers(): void
    {
        $question = $this->question(['type' => QuestionType::MultipleChoice]);
        QuestionOption::factory()->for($question)->count(2)->create();
        $this->answer($question);
        $otherQuestion = $this->question();
        Sanctum::actingAs($this->learner);

        $this->deleteJson("/api/v1/questions/{$question->id}")->assertNoContent();

        $this->assertModelMissing($question);
        $this->assertDatabaseCount('question_options', 0);
        $this->assertDatabaseCount('answers', 0);
        $this->assertModelExists($otherQuestion);
    }

    public function test_someone_elses_question_is_not_found(): void
    {
        $someoneElses = Question::factory()->create();
        Sanctum::actingAs($this->learner);

        $this->deleteJson("/api/v1/questions/{$someoneElses->id}")->assertNotFound();

        $this->assertModelExists($someoneElses);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function question(array $attributes = []): Question
    {
        return Question::factory()->for($this->topic)->create(['subtopic_id' => $this->subtopic->id, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function answer(Question $question, array $attributes = []): Answer
    {
        return Answer::factory()->for($question)->for($this->learner)->create($attributes);
    }
}
