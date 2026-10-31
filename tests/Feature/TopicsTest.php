<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Models\Answer;
use App\Models\Category;
use App\Models\DailyActivity;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Material;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_topic_in_one_of_your_categories(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        Sanctum::actingAs($learner);

        $response = $this->postJson('/api/v1/topics', [
            'title' => 'Astronomy',
            'description' => 'Stars and planets.',
            'notes' => 'From the planetarium.',
            'category_id' => $science->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Astronomy')
            ->assertJsonPath('description', 'Stars and planets.')
            ->assertJsonPath('notes', 'From the planetarium.')
            ->assertJsonPath('category', ['id' => $science->id, 'name' => 'Science'])
            ->assertJsonPath('goal', ['description' => null, 'deadline' => null, 'recall_percentage' => null])
            ->assertJsonPath('subtopics', []);

        $topic = Topic::sole();
        $this->assertTrue($topic->user->is($learner));
        $this->assertSame($response->json('id'), $topic->id);
    }

    public function test_naming_a_category_reuses_one_you_have_or_makes_a_new_one(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        Category::factory()->create(['name' => 'Music']);
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_name' => ' science '])
            ->assertCreated()
            ->assertJsonPath('category.id', $science->id);

        $this->postJson('/api/v1/topics', ['title' => 'Jazz', 'category_name' => 'Music'])
            ->assertCreated()
            ->assertJsonPath('category.name', 'Music');

        $this->assertSame(['Science', 'Music'], $learner->categories()->orderBy('id')->pluck('name')->all());
    }

    public function test_a_topic_needs_a_name_and_exactly_one_category_of_your_own(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create();
        $someoneElsesCategory = Category::factory()->create();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/topics', ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Give your topic a name.',
                'category_id' => 'Pick a category, or name a new one.',
            ]);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_id' => $science->id, 'category_name' => 'Space'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'Pick a category or name a new one, not both.']);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_id' => $someoneElsesCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => "We couldn't find that category. Pick one from the list."]);

        $this->postJson('/api/v1/topics', ['title' => str_repeat('a', 256), 'category_name' => str_repeat('b', 61), 'notes' => str_repeat('c', 30001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Keep the topic name under 255 characters.',
                'category_name' => 'Keep the category name under 60 characters.',
                'notes' => 'Keep your notes under 30,000 characters.',
            ]);

        $this->assertDatabaseCount('topics', 0);
    }

    public function test_editing_a_topic_changes_its_details_and_category_but_keeps_its_goal_and_subtopics(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Astronmy', 'goal' => 'Name the planets']);
        Subtopic::factory()->for($topic)->create(['title' => 'Planets']);
        Sanctum::actingAs($learner);

        $response = $this->putJson("/api/v1/topics/{$topic->id}", [
            'title' => 'Astronomy',
            'description' => null,
            'notes' => 'New notes',
            'category_name' => 'Space',
        ]);

        $response->assertOk()
            ->assertJsonPath('title', 'Astronomy')
            ->assertJsonPath('description', null)
            ->assertJsonPath('notes', 'New notes')
            ->assertJsonPath('category.name', 'Space')
            ->assertJsonPath('goal.description', 'Name the planets')
            ->assertJsonPath('subtopics.0.title', 'Planets');

        $this->assertSame('Space', $topic->fresh()->category->name);
    }

    public function test_someone_elses_topic_cannot_be_edited(): void
    {
        $learner = User::factory()->create();
        $someoneElsesTopic = Topic::factory()->create(['title' => 'Theirs']);
        Sanctum::actingAs($learner);

        $this->putJson("/api/v1/topics/{$someoneElsesTopic->id}", ['title' => 'Mine', 'category_name' => 'Science'])->assertNotFound();

        $this->assertSame('Theirs', $someoneElsesTopic->fresh()->title);
    }

    public function test_topics_need_a_token(): void
    {
        $this->postJson('/api/v1/topics', ['title' => 'Astronomy'])->assertUnauthorized();
        $this->putJson('/api/v1/topics/1', ['title' => 'Astronomy'])->assertUnauthorized();
        $this->deleteJson('/api/v1/topics/1')->assertUnauthorized();
    }

    public function test_deleting_a_topic_removes_everything_in_it_and_about_it(): void
    {
        Storage::fake('local');
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $flashcard = Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create();
        $question = Question::factory()->for($topic)->for($subtopic)->create();
        $practiceSession = StudySession::factory()->for($learner)->for($topic)->create(['kind' => SessionKind::Review]);
        FlashcardReview::factory()->for($flashcard)->for($practiceSession)->create();
        Answer::factory()->for($question)->for($learner)->for($practiceSession)->create();
        SubtopicProgress::factory()->for($learner)->for($subtopic)->create();
        $conversation = StudySession::factory()->for($learner)->for($topic)->create();
        $message = Message::factory()->for($conversation)->create();
        $savedFile = Material::factory()->for($topic)->create(['message_id' => $message->id]);
        $unsavedConversation = StudySession::factory()->for($learner)->for($topic)->create(['ended_at' => null]);
        $fileInUnsavedConversation = Material::factory()->unsent()->create([
            'user_id' => $learner->id,
            'message_id' => Message::factory()->for($unsavedConversation)->create()->id,
        ]);
        Storage::disk('local')->put($savedFile->storage_path, 'pdf');
        Storage::disk('local')->put($fileInUnsavedConversation->storage_path, 'pdf');
        $practisedToday = DailyActivity::factory()->for($learner)->create();
        $otherTopic = Topic::factory()->for($learner)->create();
        $otherFlashcard = Flashcard::factory()->for($learner)->for($otherTopic)->create();
        $otherConversation = StudySession::factory()->for($learner)->for($otherTopic)->create();
        Sanctum::actingAs($learner);

        $this->deleteJson("/api/v1/topics/{$topic->id}")->assertNoContent();

        foreach ([$topic, $subtopic, $flashcard, $question, $practiceSession, $conversation, $message, $unsavedConversation, $savedFile, $fileInUnsavedConversation] as $model) {
            $this->assertModelMissing($model);
        }
        $this->assertDatabaseCount('flashcard_reviews', 0);
        $this->assertDatabaseCount('answers', 0);
        $this->assertDatabaseCount('subtopic_progress', 0);
        Storage::disk('local')->assertMissing([$savedFile->storage_path, $fileInUnsavedConversation->storage_path]);
        // The days practised are a record of effort, not of the topic: the streak and XP stay.
        $this->assertModelExists($practisedToday);
        $this->assertModelExists($otherTopic);
        $this->assertModelExists($otherFlashcard);
        $this->assertModelExists($otherConversation);
    }

    public function test_someone_elses_topic_cannot_be_deleted(): void
    {
        $someoneElses = Topic::factory()->create();
        Subtopic::factory()->for($someoneElses)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/v1/topics/{$someoneElses->id}")->assertNotFound();
        $this->deleteJson('/api/v1/topics/999')->assertNotFound();

        $this->assertModelExists($someoneElses);
        $this->assertDatabaseCount('subtopics', 1);
    }
}
