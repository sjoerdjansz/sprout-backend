<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a user or a topic must erase everything under it (GDPR), while
 * other learners' data stays untouched.
 */
class LearningDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_topic_erases_everything_learned_in_it(): void
    {
        $user = User::factory()->create();
        $topic = $this->topicWithLearningData($user);
        $otherTopic = $this->topicWithLearningData($user);

        $topic->delete();

        foreach ([Subtopic::class, Flashcard::class, Question::class, StudySession::class] as $model) {
            $this->assertSame(0, $model::where('topic_id', $topic->id)->count(), "$model rows were left behind");
        }
        $this->assertSame(1, Flashcard::count());
        $this->assertSame(1, FlashcardReview::count());
        $this->assertSame(1, Answer::count());
        $this->assertSame(1, Message::count());
        $this->assertSame(1, SubtopicProgress::count());
        $this->assertModelExists($otherTopic);
    }

    public function test_deleting_a_user_erases_all_their_learning_data_but_not_other_users_data(): void
    {
        $user = User::factory()->create();
        $this->topicWithLearningData($user);
        $otherUser = User::factory()->create();
        $this->topicWithLearningData($otherUser);

        $user->delete();

        foreach ([Topic::class, Flashcard::class, Answer::class, StudySession::class, SubtopicProgress::class] as $model) {
            $this->assertSame(0, $model::where('user_id', $user->id)->count(), "$model rows were left behind");
            $this->assertSame(1, $model::where('user_id', $otherUser->id)->count());
        }
    }

    private function topicWithLearningData(User $user): Topic
    {
        $topic = Topic::factory()->for($user)->create();
        $subtopic = Subtopic::factory()->for($topic)->create();
        $session = StudySession::factory()->for($user)->for($topic)->create();
        $message = Message::factory()->for($session)->create();
        $flashcard = Flashcard::factory()->for($user)->for($topic)->for($subtopic)->create(['source_message_id' => $message->id]);
        FlashcardReview::factory()->for($flashcard)->for($session)->create();
        $question = Question::factory()->for($topic)->for($subtopic)->create();
        Answer::factory()->for($question)->for($user)->for($session)->create();
        SubtopicProgress::factory()->for($user)->for($subtopic)->create();

        return $topic;
    }
}
