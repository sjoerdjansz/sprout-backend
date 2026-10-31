<?php

namespace Tests\Feature;

use App\Enums\AiCallKind;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_kept_subtopics_are_added_at_the_end_and_those_with_notes_get_material_in_the_background(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Coach better']);
        Subtopic::factory()->for($topic)->create(['position' => 3]);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson("/api/v1/topics/{$topic->id}/subtopics", ['subtopics' => [
            ['title' => 'Cadence', 'description' => 'Steps per minute.', 'notes' => 'Most runners land around 170 to 180 steps per minute.'],
            ['title' => 'Warming up', 'description' => null, 'notes' => null],
            ['title' => 'Stride length', 'notes' => 'How far each step goes.'],
        ]]);

        $response->assertCreated()
            ->assertJsonCount(3, 'subtopics')
            ->assertJsonPath('subtopics.0.topic_id', $topic->id)
            ->assertJsonPath('subtopics.0.title', 'Cadence')
            ->assertJsonPath('subtopics.0.description', 'Steps per minute.')
            ->assertJsonPath('subtopics.0.notes', 'Most runners land around 170 to 180 steps per minute.')
            ->assertJsonPath('subtopics.0.generation_status', 'generating')
            ->assertJsonPath('subtopics.0.is_new', true)
            ->assertJsonPath('subtopics.1.title', 'Warming up')
            ->assertJsonPath('subtopics.1.notes', null)
            ->assertJsonPath('subtopics.1.generation_status', 'ready')
            ->assertJsonPath('subtopics.2.title', 'Stride length')
            ->assertJsonPath('subtopics.2.generation_status', 'generating')
            ->assertJsonPath('added', ['subtopics' => 3]);

        $this->assertSame(
            ['Cadence' => 4, 'Warming up' => 5, 'Stride length' => 6],
            $topic->subtopics()->where('position', '>', 3)->orderBy('position')->pluck('position', 'title')->all(),
        );
        Http::assertNothingSent();
        Queue::assertPushed(GenerateSubtopicMaterial::class, 2);
        Queue::assertNotPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->title === 'Warming up');
    }

    public function test_subtopics_without_notes_are_added_without_a_job(): void
    {
        Queue::fake();
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopics", ['subtopics' => [['title' => 'Cadence'], ['title' => 'Stride length']]])
            ->assertCreated()
            ->assertJsonPath('added', ['subtopics' => 2]);

        Queue::assertNothingPushed();
    }

    public function test_added_subtopics_are_validated_and_must_fit_in_the_topic(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($topic)->create(['title' => 'Cadence']);
        Subtopic::factory()->for($topic)->count(26)->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/topics/{$topic->id}/subtopics", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtopics' => 'Keep at least one subtopic to add.']);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopics", ['subtopics' => [
            ['title' => ''],
            ['title' => 'Stride', 'description' => str_repeat('a', 1001), 'notes' => str_repeat('b', 10001)],
            ['title' => 'STRIDE'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'subtopics.0.title' => 'Give every subtopic a name.',
                'subtopics.1.description' => 'Keep the description under 1,000 characters.',
                'subtopics.1.notes' => 'Keep the notes of a subtopic under 10,000 characters.',
                'subtopics.2.title' => 'Two subtopics have the same name. Rename or remove one.',
            ]);

        $this->postJson("/api/v1/topics/{$topic->id}/subtopics", ['subtopics' => [
            ['title' => 'Stride'], ['title' => 'cadence'], ['title' => 'Breathing'], ['title' => 'Posture'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'subtopics.1.title' => 'This topic already has a subtopic with that name. Pick another name.',
                'subtopics' => 'This topic has room for 3 more subtopics. Leave some out.',
            ]);

        $this->assertDatabaseCount('subtopics', 27);
        Http::assertNothingSent();
    }

    public function test_someone_elses_topic_gets_no_subtopics(): void
    {
        $someoneElsesTopic = Topic::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/topics/{$someoneElsesTopic->id}/subtopics", ['subtopics' => [['title' => 'Mine']]])->assertNotFound();

        $this->assertDatabaseCount('subtopics', 0);
    }

    /**
     * @return array{title: string, description: string, notes: string}
     */
    private function suggestion(string $title, string $notes = 'Some notes.'): array
    {
        return ['title' => $title, 'description' => "What {$title} covers.", 'notes' => $notes];
    }
}
