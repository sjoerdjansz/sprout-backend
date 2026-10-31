<?php

namespace Tests\Feature;

use App\Enums\AiCallKind;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

/**
 * Building a topic from a source (specs/topic-from-source.md): suggesting subtopics from a topic's notes,
 * then adding the ones the learner kept with their flashcards and questions.
 */
class TopicFromSourceTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    private const SOURCE = "# Week 1: Cadence\nSteps per minute.\n\n# Week 2: Stride length\nHow far each step goes.";

    public function test_subtopics_are_suggested_from_the_topics_source_without_saving_anything(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'description' => 'Run better.', 'notes' => self::SOURCE]);
        Subtopic::factory()->for($topic)->create(['title' => 'Breathing', 'position' => 1]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['suggestions' => [
            $this->suggestion('Cadence', 'Steps per minute.'),
            $this->suggestion('breathing', 'Already a subtopic.'),
            $this->suggestion('CADENCE', 'The same title twice.'),
            ['title' => ' Stride length ', 'description' => '', 'notes' => ''],
        ]])]);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertOk()
            ->assertExactJson(['suggestions' => [
                ['title' => 'Cadence', 'description' => 'What Cadence covers.', 'notes' => 'Steps per minute.'],
                ['title' => 'Stride length', 'description' => null, 'notes' => null],
            ]]);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $input === [
                'topic' => ['title' => 'Running', 'description' => 'Run better.', 'notes' => self::SOURCE],
                'existing_subtopics' => ['Breathing'],
                'max_suggestions' => 29,
            ];
        });
        $this->assertDatabaseCount('subtopics', 1);
        $this->assertSame([AiCallKind::SubtopicSuggestions], $learner->aiCalls()->pluck('kind')->all());
    }

    public function test_no_more_subtopics_are_suggested_than_still_fit_in_the_topic(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['notes' => self::SOURCE]);
        Subtopic::factory()->for($topic)->count(28)->create();
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['suggestions' => [
            $this->suggestion('One'), $this->suggestion('Two'), $this->suggestion('Three'),
        ]])]);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertOk()
            ->assertJsonCount(2, 'suggestions')
            ->assertJsonPath('suggestions.1.title', 'Two');

        Http::assertSent(fn (Request $request): bool => json_decode($request['messages'][1]['content'], true)['max_suggestions'] === 2);
    }

    public function test_a_source_the_subtopics_already_cover_gets_no_suggestions(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['notes' => self::SOURCE]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['suggestions' => []])]);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);
    }

    public function test_suggesting_needs_something_to_read_and_room_in_the_topic(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $emptyTopic = Topic::factory()->for($learner)->create(['description' => null, 'notes' => null]);
        $fullTopic = Topic::factory()->for($learner)->create(['notes' => self::SOURCE]);
        Subtopic::factory()->for($fullTopic)->count(30)->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/topics/{$emptyTopic->id}/subtopic-suggestions")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic' => 'Add a description or notes to this topic first, so Sprout has something to read.']);

        $this->postJson("/api/v1/topics/{$fullTopic->id}/subtopic-suggestions")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic' => 'This topic has 30 subtopics, the most it can have.']);

        Http::assertNothingSent();
    }

    public function test_someone_elses_topic_gets_no_suggestions(): void
    {
        $someoneElsesTopic = Topic::factory()->create(['notes' => self::SOURCE]);
        Http::fake();

        $this->postJson("/api/v1/topics/{$someoneElsesTopic->id}/subtopic-suggestions")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/topics/{$someoneElsesTopic->id}/subtopic-suggestions")->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_suggesting_fails_with_a_503_when_the_ai_fails_or_a_part_is_too_long_to_add(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['notes' => self::SOURCE]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->pushResponse($this->openAiError(500, 'server_error'))
            ->pushResponse($this->openAiAnswer(['suggestions' => [$this->suggestion('Cadence', str_repeat('a', 10001))]]))]);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");
    }

    public function test_suggesting_is_rate_limited(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['notes' => self::SOURCE]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['suggestions' => []])]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")->assertOk();
        }

        $this->postJson("/api/v1/topics/{$topic->id}/subtopic-suggestions")
            ->assertTooManyRequests()
            ->assertJsonPath('message', "You've asked for suggestions a few times in a row. Wait a minute and try again.");
    }

    /**
     * @return array{title: string, description: string, notes: string}
     */
    private function suggestion(string $title, string $notes = 'Some notes.'): array
    {
        return ['title' => $title, 'description' => "What {$title} covers.", 'notes' => $notes];
    }
}
