<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Models\Answer;
use App\Models\Category;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Question;
use App\Models\StudySession;
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
        $subtopic = Subtopic::factory()->for($topic)->create(['title' => 'Photosynthesis', 'content' => 'Light, water and CO2.']);
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
            ->assertJsonPath('counts', ['flashcards' => 2, 'questions' => 1, 'sessions' => 2]);
    }

    public function test_someone_elses_subtopic_is_not_found(): void
    {
        $learner = User::factory()->create();
        $someoneElses = Subtopic::factory()->create(['content' => 'Notes.']);
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

    public function test_deleting_a_subtopic_removes_its_cards_questions_answers_and_progress(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $otherSubtopic = Subtopic::factory()->for($topic)->create();
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
        $this->assertDatabaseCount('flashcard_reviews', 0);
        $this->assertDatabaseCount('answers', 0);
        $this->assertDatabaseCount('subtopic_progress', 0);
        $this->assertModelExists($otherSubtopic);
        $this->assertModelExists($otherFlashcard);
        $this->assertModelExists($topic);
    }

    public function test_generating_adds_what_the_existing_cards_and_questions_dont_cover_yet(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Biology', 'goal' => 'Pass the exam']);
        $subtopic = Subtopic::factory()->for($topic)->create(['title' => 'Photosynthesis', 'content' => 'Light, water and CO2 become sugar.']);
        Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create(['front' => 'What does a plant need?']);
        Question::factory()->for($topic)->for($subtopic)->create(['prompt' => 'Explain photosynthesis.']);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [[
            'title' => 'Photosynthesis',
            'flashcards' => [['front' => 'What is made?', 'back' => 'Sugar.'], ['front' => 'What is released?', 'back' => 'Oxygen.']],
            'questions' => [['type' => 'open', 'prompt' => 'Why is light needed?', 'model_answer' => 'Energy.', 'options' => []]],
        ]]])]);

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertOk()
            ->assertJsonPath('subtopic.id', $subtopic->id)
            ->assertJsonPath('subtopic.due_cards', 3)
            ->assertJsonPath('added', ['flashcards' => 2, 'questions' => 1]);

        $this->assertSame(3, $subtopic->flashcards()->where('user_id', $learner->id)->count());
        $this->assertSame(2, $subtopic->questions()->count());
        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $input['topic'] === ['title' => 'Biology', 'goal' => 'Pass the exam']
                && $input['subtopics'] === [[
                    'title' => 'Photosynthesis',
                    'content' => 'Light, water and CO2 become sugar.',
                    'existing_items' => ['What does a plant need?', 'Explain photosynthesis.'],
                ]];
        });
    }

    public function test_generating_needs_notes_to_generate_from(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create(['content' => null]);
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes' => 'Add notes to this subtopic first, so we can make cards from them.']);

        Http::assertNothingSent();
    }

    public function test_nothing_is_added_when_generating_fails(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create(['content' => 'Notes.']);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")->assertServiceUnavailable();

        $this->assertDatabaseCount('flashcards', 0);
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_generating_is_rate_limited_per_learner(): void
    {
        $learner = User::factory()->create();
        $subtopic = Subtopic::factory()->for(Topic::factory()->for($learner))->create(['content' => null]);
        Sanctum::actingAs($learner);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")->assertUnprocessable();
        }

        $this->postJson("/api/v1/subtopics/{$subtopic->id}/generate")
            ->assertTooManyRequests()
            ->assertJsonPath('message', "You've asked for new cards a few times in a row. Wait a minute and try again.");
    }
}
