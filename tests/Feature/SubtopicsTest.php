<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\SourceType;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Flashcard;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class SubtopicsTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_adding_a_subtopic_with_notes_queues_cards_and_questions_from_them(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Coach better']);
        Subtopic::factory()->for($topic)->create(['position' => 4]);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson('/api/v1/subtopics', [
            'topic_id' => $topic->id,
            'title' => 'Cadence',
            'description' => 'Steps per minute.',
            'notes' => 'Most runners land around 170 to 180 steps per minute.',
        ]);

        $response->assertCreated()
            ->assertExactJsonStructure(['subtopic' => ['id', 'topic_id', 'title', 'description', 'notes', 'generation_status', 'mastery', 'due_cards', 'next_review_on', 'is_new', 'slipping', 'facets' => ['cards' => ['percent', 'holding'], 'explain' => ['percent', 'holding'], 'quiz' => ['percent', 'holding']]]])
            ->assertJsonPath('subtopic.topic_id', $topic->id)
            ->assertJsonPath('subtopic.title', 'Cadence')
            ->assertJsonPath('subtopic.description', 'Steps per minute.')
            ->assertJsonPath('subtopic.notes', 'Most runners land around 170 to 180 steps per minute.')
            ->assertJsonPath('subtopic.generation_status', 'generating')
            ->assertJsonPath('subtopic.is_new', true);

        $subtopic = Subtopic::where('title', 'Cadence')->sole();
        $this->assertSame(5, $subtopic->position);
        Http::assertNothingSent();
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($subtopic) && $job->sourceMessageId === null);
    }

    public function test_a_subtopic_without_notes_is_added_ready_without_a_job(): void
    {
        Queue::fake();
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/subtopics', ['topic_id' => $topic->id, 'title' => 'Cadence'])
            ->assertCreated()
            ->assertJsonPath('subtopic.notes', null)
            ->assertJsonPath('subtopic.generation_status', 'ready');

        Queue::assertNothingPushed();
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

    public function test_changed_notes_queue_material_and_unchanged_notes_queue_nothing(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->withOwnNotes('Steps per minute.')->create(['title' => 'Cadence']);
        Sanctum::actingAs($learner);

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", [
            'topic_id' => $topic->id,
            'title' => 'Cadence',
            'description' => 'How fast your feet turn over.',
            'notes' => 'Steps per minute. Elite runners often go above 180.',
        ])
            ->assertOk()
            ->assertJsonPath('subtopic.description', 'How fast your feet turn over.')
            ->assertJsonPath('subtopic.generation_status', 'generating');

        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
        $subtopic->refresh()->update(['generation_status' => GenerationStatus::Ready]);

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", [
            'topic_id' => $topic->id,
            'title' => 'Step rate',
            'notes' => 'Steps per minute. Elite runners often go above 180.',
        ])
            ->assertOk()
            ->assertJsonPath('subtopic.title', 'Step rate')
            ->assertJsonPath('subtopic.description', null)
            ->assertJsonPath('subtopic.generation_status', 'ready');

        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
    }

    public function test_the_form_edits_only_the_learners_own_notes(): void
    {
        Queue::fake();
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->withTutorNotes('From the chat.')->create(['title' => 'Cadence']);
        Sanctum::actingAs($learner);

        $this->putJson("/api/v1/subtopics/{$subtopic->id}", ['topic_id' => $topic->id, 'title' => 'Cadence', 'notes' => 'My coach says 170.'])
            ->assertOk()
            ->assertJsonPath('subtopic.notes', 'My coach says 170.')
            ->assertJsonPath('subtopic.generation_status', 'generating');
        $this->assertSame('From the chat.', $subtopic->fresh()->sourceContent(SourceType::Tutor));
        $this->assertSame('My coach says 170.', $subtopic->fresh()->sourceContent(SourceType::Own));

        // Clearing the notes removes the learner's own notes only, and makes nothing new.
        $subtopic->fresh()->update(['generation_status' => GenerationStatus::Ready]);
        $this->putJson("/api/v1/subtopics/{$subtopic->id}", ['topic_id' => $topic->id, 'title' => 'Cadence', 'notes' => null])
            ->assertOk()
            ->assertJsonPath('subtopic.notes', null)
            ->assertJsonPath('subtopic.generation_status', 'ready');
        $this->assertNull($subtopic->fresh()->sourceContent(SourceType::Own));
        $this->assertSame('From the chat.', $subtopic->fresh()->sourceContent(SourceType::Tutor));
        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
    }

    public function test_moving_a_subtopic_takes_its_cards_questions_and_progress_along(): void
    {
        $learner = User::factory()->create();
        $from = Topic::factory()->for($learner)->create();
        $to = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($to)->create(['position' => 3]);
        $subtopic = Subtopic::factory()->for($from)->withOwnNotes('Steps.')->create(['title' => 'Cadence']);
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
}
