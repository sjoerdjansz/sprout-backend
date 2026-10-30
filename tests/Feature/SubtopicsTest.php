<?php

namespace Tests\Feature;

use App\Models\Flashcard;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class SubtopicsTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_adding_a_subtopic_with_notes_generates_cards_and_questions_from_them(): void
    {
        $learner = $this->learnerWithKey();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Coach better']);
        Subtopic::factory()->for($topic)->create(['position' => 4]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$this->material('Cadence', flashcards: 2)]])]);

        $response = $this->postJson('/api/v1/subtopics', [
            'topic_id' => $topic->id,
            'title' => 'Cadence',
            'description' => 'Steps per minute.',
            'notes' => 'Most runners land around 170 to 180 steps per minute.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('subtopic.topic_id', $topic->id)
            ->assertJsonPath('subtopic.title', 'Cadence')
            ->assertJsonPath('subtopic.description', 'Steps per minute.')
            ->assertJsonPath('subtopic.notes', 'Most runners land around 170 to 180 steps per minute.')
            ->assertJsonPath('subtopic.is_new', true)
            ->assertJsonPath('subtopic.due_cards', 2)
            ->assertJsonPath('added', ['flashcards' => 2, 'questions' => 1]);

        $subtopic = Subtopic::where('title', 'Cadence')->sole();
        $this->assertSame(5, $subtopic->position);
        $this->assertSame(2, $subtopic->flashcards()->where('user_id', $learner->id)->where('topic_id', $topic->id)->count());
        $this->assertNull($subtopic->flashcards()->first()->source_message_id);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $input['topic'] === ['title' => 'Running', 'goal' => 'Coach better']
                && $input['subtopics'] === [['title' => 'Cadence', 'content' => 'Most runners land around 170 to 180 steps per minute.', 'existing_items' => []]];
        });
    }

    public function test_a_subtopic_without_notes_is_added_without_an_ai_call(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson('/api/v1/subtopics', ['topic_id' => $topic->id, 'title' => 'Cadence'])
            ->assertCreated()
            ->assertJsonPath('subtopic.notes', null)
            ->assertJsonPath('added', ['flashcards' => 0, 'questions' => 0]);

        Http::assertNothingSent();
    }

    public function test_a_subtopic_needs_a_name_the_topic_does_not_have_yet_and_room_in_it(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($topic)->create(['title' => 'Cadence']);
        $fullTopic = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($fullTopic)->count(30)->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson('/api/v1/subtopics', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'topic_id' => 'Pick the main topic this subtopic belongs to.',
                'title' => 'Give your subtopic a name.',
            ]);

        $this->postJson('/api/v1/subtopics', ['topic_id' => $topic->id, 'title' => 'CADENCE', 'notes' => 'Steps.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title' => 'This topic already has a subtopic with that name. Pick another name.']);

        $this->postJson('/api/v1/subtopics', ['topic_id' => $fullTopic->id, 'title' => 'One more'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id' => 'This topic has 30 subtopics, the most it can have. Add it to another topic.']);

        $this->postJson('/api/v1/subtopics', ['topic_id' => Topic::factory()->create()->id, 'title' => 'Stride'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id' => "We couldn't find that topic. Pick one from your knowledge map."]);

        Http::assertNothingSent();
    }

    public function test_nothing_is_added_when_generating_fails(): void
    {
        $learner = $this->learnerWithKey();
        $topic = Topic::factory()->for($learner)->create();
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);

        $this->postJson('/api/v1/subtopics', ['topic_id' => $topic->id, 'title' => 'Cadence', 'notes' => 'Steps per minute.'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");

        $this->assertDatabaseCount('subtopics', 0);
    }

    public function test_changed_notes_get_material_for_what_is_new_and_unchanged_notes_get_nothing(): void
    {
        $learner = $this->learnerWithKey();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create(['title' => 'Cadence', 'content' => 'Steps per minute.']);
        Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create(['front' => 'What is cadence?']);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$this->material('Cadence', flashcards: 1)]])]);

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", [
            'topic_id' => $topic->id,
            'title' => 'Cadence',
            'description' => 'How fast your feet turn over.',
            'notes' => 'Steps per minute. Elite runners often go above 180.',
        ])
            ->assertOk()
            ->assertJsonPath('subtopic.description', 'How fast your feet turn over.')
            ->assertJsonPath('added', ['flashcards' => 1, 'questions' => 1]);

        Http::assertSent(fn (Request $request): bool => json_decode($request['messages'][1]['content'], true)['subtopics'][0]['existing_items'] === ['What is cadence?']);
        Http::fake();

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", [
            'topic_id' => $topic->id,
            'title' => 'Step rate',
            'notes' => 'Steps per minute. Elite runners often go above 180.',
        ])
            ->assertOk()
            ->assertJsonPath('subtopic.title', 'Step rate')
            ->assertJsonPath('subtopic.description', null)
            ->assertJsonPath('added', ['flashcards' => 0, 'questions' => 0]);

        Http::assertNothingSent();
    }

    public function test_moving_a_subtopic_takes_its_cards_questions_and_progress_along(): void
    {
        $learner = User::factory()->create();
        $from = Topic::factory()->for($learner)->create();
        $to = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($to)->create(['position' => 3]);
        $subtopic = Subtopic::factory()->for($from)->create(['title' => 'Cadence', 'content' => 'Steps.']);
        $flashcard = Flashcard::factory()->for($learner)->for($from)->for($subtopic)->create(['due_at' => now()->subDay()]);
        $question = Question::factory()->for($from)->for($subtopic)->create();
        SubtopicProgress::factory()->for($learner)->for($subtopic)->create(['mastery' => 64]);
        Sanctum::actingAs($learner);
        Http::fake();

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", ['topic_id' => $to->id, 'title' => 'Cadence', 'notes' => 'Steps.'])
            ->assertOk()
            ->assertJsonPath('subtopic.topic_id', $to->id)
            ->assertJsonPath('subtopic.mastery', 64)
            ->assertJsonPath('subtopic.due_cards', 1);

        $this->assertSame(4, $subtopic->fresh()->position);
        $this->assertSame($to->id, $flashcard->fresh()->topic_id);
        $this->assertSame($to->id, $question->fresh()->topic_id);
        Http::assertNothingSent();
    }

    public function test_someone_elses_subtopic_cannot_be_edited_or_moved_into(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $mine = Subtopic::factory()->for($topic)->create();
        $someoneElsesTopic = Topic::factory()->create();
        $someoneElses = Subtopic::factory()->for($someoneElsesTopic)->create(['title' => 'Theirs']);
        Sanctum::actingAs($learner);

        $this->putJson("/api/v1/subtopics/{$someoneElses->id}", ['topic_id' => $topic->id, 'title' => 'Mine'])->assertNotFound();
        $this->putJson("/api/v1/subtopics/{$mine->id}", ['topic_id' => $someoneElsesTopic->id, 'title' => 'Moved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic_id']);

        $this->assertSame('Theirs', $someoneElses->fresh()->title);
        $this->assertSame($topic->id, $mine->fresh()->topic_id);
    }

    /**
     * Generated material for one subtopic, as the AI returns it: flashcards and one open question.
     *
     * @return array<string, mixed>
     */
    private function material(string $title, int $flashcards): array
    {
        return [
            'title' => $title,
            'flashcards' => array_map(fn (int $number): array => [
                'front' => "Question {$number} about {$title}?",
                'back' => "Answer {$number}.",
            ], range(1, $flashcards)),
            'questions' => [
                ['type' => 'open', 'prompt' => "Explain {$title}.", 'model_answer' => 'A good answer.', 'options' => []],
            ],
        ];
    }
}
