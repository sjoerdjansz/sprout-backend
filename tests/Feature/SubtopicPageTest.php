<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Enums\SessionKind;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Answer;
use App\Models\Category;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Material;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\SubtopicSource;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class SubtopicPageTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_guest_cannot_open_a_subtopic(): void
    {
        $this->getJson('/api/v1/subtopics/1')->assertUnauthorized();
    }

    public function test_a_subtopic_comes_with_its_progress_topic_and_counts_for_the_tabs(): void
    {
        $learner = User::factory()->create();
        $category = Category::factory()->for($learner)->create(['name' => 'Science']);
        $topic = Topic::factory()->for($learner)->for($category)->create(['title' => 'Biology']);
        $subtopic = Subtopic::factory()->for($topic)->withOwnNotes('Light, water and CO2.')->create(['title' => 'Photosynthesis']);
        Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create(['due_at' => now()->subDay()]);
        Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create(['suspended_at' => now()]);
        Question::factory()->for($topic)->for($subtopic)->create();
        SubtopicProgress::factory()->for($learner)->for($subtopic)->create(['mastery' => 64]);
        StudySession::factory()->for($learner)->for($topic)->count(2)->create();
        StudySession::factory()->for($learner)->for($topic)->create(['kind' => SessionKind::Review]);
        StudySession::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/subtopics/{$subtopic->id}")
            ->assertOk()
            ->assertJsonPath('id', $subtopic->id)
            ->assertJsonPath('title', 'Photosynthesis')
            ->assertJsonPath('notes', 'Light, water and CO2.')
            ->assertJsonPath('mastery', 64)
            ->assertJsonPath('due_cards', 1)
            ->assertJsonPath('facets.cards', ['percent' => null, 'holding' => false])
            ->assertJsonPath('topic', ['id' => $topic->id, 'title' => 'Biology', 'category' => ['id' => $category->id, 'name' => 'Science']])
            ->assertJsonPath('counts', ['flashcards' => 2, 'questions' => 1, 'sessions' => 2])
            ->assertJsonPath('source_count', 1);
    }

    public function test_a_subtopic_shows_its_sources_tutor_notes_first_then_your_notes_then_files(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $lecture = Material::factory()->for($topic)->create(['original_filename' => 'Lecture 1.pdf']);
        $filePart = SubtopicSource::factory()->for($subtopic)->file($lecture)->create(['content' => 'Cadence is steps per minute.']);
        $ownNotes = SubtopicSource::factory()->for($subtopic)->own()->create(['content' => 'My coach says 170.']);
        $tutorNotes = SubtopicSource::factory()->for($subtopic)->tutor()->create(['content' => 'Steps per minute.']);
        Sanctum::actingAs($learner);

        $this->getJson("/api/v1/subtopics/{$subtopic->id}")
            ->assertOk()
            ->assertJsonPath('notes', 'My coach says 170.')
            ->assertJsonPath('source_count', 3)
            ->assertJsonPath('sources.0', [
                'id' => $tutorNotes->id,
                'type' => 'tutor',
                'content' => 'Steps per minute.',
                'material' => null,
                'updated_at' => $tutorNotes->updated_at->toJSON(),
            ])
            ->assertJsonPath('sources.1.id', $ownNotes->id)
            ->assertJsonPath('sources.1.type', 'own')
            ->assertJsonPath('sources.2.id', $filePart->id)
            ->assertJsonPath('sources.2.type', 'file')
            ->assertJsonPath('sources.2.content', 'Cadence is steps per minute.')
            ->assertJsonPath('sources.2.material', ['id' => $lecture->id, 'original_filename' => 'Lecture 1.pdf']);
    }

    public function test_someone_elses_subtopic_is_not_found(): void
    {
        $learner = User::factory()->create();
        $someoneElses = Subtopic::factory()->withTutorNotes()->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->getJson("/api/v1/subtopics/{$someoneElses->id}")->assertNotFound();
        $this->deleteJson("/api/v1/subtopics/{$someoneElses->id}")->assertNotFound();
        $this->postJson("/api/v1/subtopics/{$someoneElses->id}/generate")->assertNotFound();
        $this->getJson("/api/v1/subtopics/{$someoneElses->id}/flashcards")->assertNotFound();
        $this->postJson("/api/v1/subtopics/{$someoneElses->id}/flashcards", ['front' => 'Q?', 'back' => 'A.'])->assertNotFound();
        $this->getJson("/api/v1/subtopics/{$someoneElses->id}/questions")->assertNotFound();
        $this->getJson('/api/v1/subtopics/999')->assertNotFound();

        $this->assertModelExists($someoneElses);
        $this->assertDatabaseCount('flashcards', 0);
        Http::assertNothingSent();
    }

    public function test_deleting_a_subtopic_removes_its_cards_questions_answers_sources_sessions_and_progress(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $otherSubtopic = Subtopic::factory()->for($topic)->create();
        $ownNotes = SubtopicSource::factory()->own()->for($subtopic)->create();
        $file = Material::factory()->for($topic)->create();
        $filePart = SubtopicSource::factory()->file($file)->for($subtopic)->create();
        $subtopicPractice = StudySession::factory()->for($learner)->for($topic)->create(['kind' => SessionKind::Review, 'subtopic_id' => $subtopic->id]);
        $topicPractice = StudySession::factory()->for($learner)->for($topic)->create(['kind' => SessionKind::Review]);
        $flashcard = Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create();
        FlashcardReview::factory()->for($flashcard)->create();
        $question = Question::factory()->for($topic)->for($subtopic)->create();
        Answer::factory()->for($question)->for($learner)->create();
        SubtopicProgress::factory()->for($learner)->for($subtopic)->create();
        $otherFlashcard = Flashcard::factory()->for($learner)->for($topic)->for($otherSubtopic)->create();
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/subtopics/{$subtopic->id}")->assertNoContent();

        $this->assertModelMissing($subtopic);
        $this->assertModelMissing($flashcard);
        $this->assertModelMissing($question);
        $this->assertModelMissing($ownNotes);
        $this->assertModelMissing($filePart);
        $this->assertModelMissing($subtopicPractice);
        // The file belongs to the topic, and its other subtopics may have parts of it.
        $this->assertModelExists($file);
        $this->assertModelExists($topicPractice);
        $this->assertDatabaseCount('flashcard_reviews', 0);
        $this->assertDatabaseCount('answers', 0);
        $this->assertDatabaseCount('subtopic_progress', 0);
        $this->assertModelExists($otherSubtopic);
        $this->assertModelExists($otherFlashcard);
        $this->assertModelExists($topic);
    }

    public function test_generating_queues_more_material_from_the_notes(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->withTutorNotes('Light, water and CO2 become sugar.')->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertAccepted()
            ->assertJsonPath('subtopic.id', $subtopic->id)
            ->assertJsonPath('subtopic.generation_status', 'generating');

        Http::assertNothingSent();
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($subtopic));
    }

    public function test_generating_needs_notes_to_generate_from(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes' => 'Add notes to this subtopic first, so we can make cards from them.']);

        Http::assertNothingSent();
    }

    public function test_a_failed_subtopic_can_be_tried_again_but_one_still_generating_cannot(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        $failed = Subtopic::factory()->for($topic)->withTutorNotes('Notes.')->create(['generation_status' => GenerationStatus::Failed]);
        $generating = Subtopic::factory()->for($topic)->withTutorNotes('Notes.')->create(['generation_status' => GenerationStatus::Generating]);
        Sanctum::actingAs($learner);

        $this->postJson("/api/v1/subtopics/{$failed->id}/generate")
            ->assertAccepted()
            ->assertJsonPath('subtopic.generation_status', 'generating');

        $this->postJson("/api/v1/subtopics/{$generating->id}/generate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subtopic' => "Sprout is still making cards for this subtopic. They'll show up in a moment."]);

        Queue::assertPushed(GenerateSubtopicMaterial::class, 1);
    }

    public function test_generating_is_rate_limited_per_learner(): void
    {
        $learner = User::factory()->create();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create();
        Sanctum::actingAs($learner);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")->assertUnprocessable();
        }

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertTooManyRequests()
            ->assertJsonPath('message', "You've asked for new cards a few times in a row. Wait a minute and try again.");
    }
}
