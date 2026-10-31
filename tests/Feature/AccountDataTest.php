<?php

namespace Tests\Feature;

use App\Enums\ReviewRating;
use App\Models\AiCredential;
use App\Models\Answer;
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
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Your data" in settings: export, reset progress and delete the account (specs/settings.md).
 */
class AccountDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cant_reach_anyones_data(): void
    {
        $this->getJson('/api/v1/user/export')->assertUnauthorized();
        $this->getJson('/api/v1/user/export/flashcards')->assertUnauthorized();
        $this->postJson('/api/v1/user/progress/reset')->assertUnauthorized();
        $this->deleteJson('/api/v1/user', ['email' => 'ada@example.com'])->assertUnauthorized();
    }

    public function test_the_export_holds_everything_of_the_learner_and_nothing_secret(): void
    {
        $learner = $this->learner();
        $topic = $this->topicWithLearningData($learner);
        AiCredential::factory()->for($learner)->create(['encrypted_api_key' => 'sk-secret-key']);
        $this->topicWithLearningData(User::factory()->create());

        $response = $this->get('/api/v1/user/export');

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="sprout-export-'.now()->toDateString().'.json"')
            ->assertJsonPath('profile.email', $learner->email)
            ->assertJsonPath('settings.ai_level', 'medium')
            ->assertJsonCount(1, 'topics')
            ->assertJsonPath('topics.0.title', $topic->title)
            ->assertJsonCount(1, 'topics.0.subtopics')
            ->assertJsonPath('topics.0.subtopics.0.mastery', 60)
            ->assertJsonPath('topics.0.flashcards.0.reviews.0.rating', 'good')
            ->assertJsonCount(1, 'topics.0.questions.0.answers')
            ->assertJsonCount(1, 'conversations')
            ->assertJsonCount(1, 'conversations.0.messages')
            ->assertJsonCount(1, 'daily_activity');
        $this->assertStringNotContainsString('sk-secret-key', $response->getContent());
    }

    public function test_cards_export_as_csv_for_anki(): void
    {
        $learner = $this->learner();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Photosynthesis']);
        $subtopic = Subtopic::factory()->for($topic)->create(['title' => 'Light reactions']);
        Flashcard::factory()->for($learner)->for($topic)->for($subtopic)->create(['front' => 'What splits water?', 'back' => 'Photosystem II, using light']);
        Flashcard::factory()->for($learner)->for($topic)->create(['front' => 'Where does it happen?', 'back' => 'In the "chloroplast"']);
        Flashcard::factory()->create(['front' => 'Someone else\'s card']);

        $response = $this->get('/api/v1/user/export/flashcards');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="sprout-flashcards-'.now()->toDateString().'.csv"');
        $this->assertSame(
            "\"What splits water?\",\"Photosystem II, using light\",Photosynthesis::Light_reactions\n"
            ."\"Where does it happen?\",\"In the \"\"chloroplast\"\"\",Photosynthesis\n",
            $response->getContent(),
        );
    }

    public function test_resetting_progress_wipes_learning_history_but_keeps_the_material(): void
    {
        $learner = $this->learner();
        $topic = $this->topicWithLearningData($learner);
        $flashcard = $topic->flashcards()->sole();
        $flashcard->update(['interval_days' => 12, 'ease_factor' => 2.8, 'repetitions' => 4, 'lapses' => 1, 'due_at' => now()->addDays(12), 'suspended_at' => now()]);
        $someoneElsesTopic = $this->topicWithLearningData(User::factory()->create());

        $this->postJson('/api/v1/user/progress/reset')->assertNoContent();

        $this->assertSame(0, FlashcardReview::whereIn('flashcard_id', $learner->flashcards()->select('id'))->count());
        $this->assertSame(0, $learner->answers()->count());
        $this->assertSame(0, $learner->subtopicProgress()->count());
        $this->assertSame(0, $learner->dailyActivities()->count());
        $flashcard->refresh();
        $this->assertSame([0, 2.5, 0, 0], [$flashcard->interval_days, $flashcard->ease_factor, $flashcard->repetitions, $flashcard->lapses]);
        $this->assertTrue($flashcard->due_at->lte(now()));
        $this->assertNotNull($flashcard->suspended_at, 'A paused card stays paused');
        $this->assertModelExists($topic);
        $this->assertSame(1, $topic->questions()->count());
        $this->assertSame(1, $learner->conversations()->count());
        $this->assertSame(1, FlashcardReview::whereIn('flashcard_id', $someoneElsesTopic->flashcards()->select('id'))->count());
    }

    public function test_deleting_the_account_needs_the_exact_email(): void
    {
        $learner = $this->learner('ada@example.com');

        $this->deleteJson('/api/v1/user', ['email' => 'ada@example.org'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Type your email address exactly as it is on your account.']);
        $this->deleteJson('/api/v1/user', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Type your email address to confirm.']);
        $this->assertModelExists($learner);
    }

    public function test_deleting_the_account_removes_everything_at_once(): void
    {
        Storage::fake('local');
        $learner = User::factory()->create(['email' => 'ada@example.com']);
        UserSetting::factory()->for($learner)->create();
        $token = $learner->createToken('magic-link')->plainTextToken;
        $topic = $this->topicWithLearningData($learner);
        $material = Material::factory()->for($topic)->create();
        Storage::disk('local')->put($material->storage_path, 'notes');
        $otherLearner = User::factory()->create();

        $this->withToken($token)->deleteJson('/api/v1/user', ['email' => ' Ada@Example.com '])->assertNoContent();

        $this->assertModelMissing($learner);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('topics', ['id' => $topic->id]);
        Storage::disk('local')->assertMissing($material->storage_path);
        $this->assertModelExists($otherLearner);
    }

    private function learner(string $email = 'ada@example.com'): User
    {
        $learner = User::factory()->create(['email' => $email]);
        UserSetting::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        return $learner;
    }

    private function topicWithLearningData(User $user): Topic
    {
        $topic = Topic::factory()->for($user)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $session = StudySession::factory()->for($user)->for($topic)->create();
        Message::factory()->for($session)->create();
        $flashcard = Flashcard::factory()->for($user)->for($topic)->for($subtopic)->create();
        FlashcardReview::factory()->for($flashcard)->for($session)->create(['rating' => ReviewRating::Good]);
        $question = Question::factory()->for($topic)->for($subtopic)->create();
        Answer::factory()->for($question)->for($user)->for($session)->create();
        SubtopicProgress::factory()->for($user)->for($subtopic)->create(['mastery' => 60]);
        DailyActivity::factory()->for($user)->create();

        return $topic;
    }
}
