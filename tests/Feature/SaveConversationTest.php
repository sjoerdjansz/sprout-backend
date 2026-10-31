<?php

namespace Tests\Feature;

use App\Enums\ContentOrigin;
use App\Enums\GenerationStatus;
use App\Enums\MessageRole;
use App\Enums\QuestionType;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Category;
use App\Models\Flashcard;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
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

class SaveConversationTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_saving_creates_the_topic_with_its_goal_and_subtopics_and_queues_their_material(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        $tutorMessage = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor]);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft());

        $response->assertCreated()
            ->assertJsonPath('topic.title', 'Biomechanics of running')
            ->assertJsonPath('topic.goal', [
                'description' => 'Explain the running gait to my athletes',
                'deadline' => now()->addMonth()->toDateString(),
                'recall_percentage' => 80,
            ])
            ->assertJsonPath('topic.subtopics.0.title', 'Gait cycle')
            ->assertJsonPath('topic.subtopics.0.generation_status', 'generating')
            ->assertJsonPath('topic.subtopics.1.title', 'Ground reaction force')
            ->assertJsonPath('added', ['subtopics' => 2]);

        $topic = Topic::sole();
        $this->assertTrue($topic->user->is($learner));
        $this->assertSame([1, 2], $topic->subtopics()->orderBy('position')->pluck('position')->all());
        $this->assertSame('Stance and swing phases.', $topic->subtopics()->where('title', 'Gait cycle')->value('content'));

        $conversation->refresh();
        $this->assertTrue($conversation->topic->is($topic));
        $this->assertTrue($conversation->isSaved());

        // Saving never waits for the AI: the material is made by a job per subtopic.
        Http::assertNothingSent();
        Queue::assertPushed(GenerateSubtopicMaterial::class, 2);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->title === 'Gait cycle' && $job->sourceMessageId === $tutorMessage->id);
    }

    public function test_the_material_made_after_saving_comes_from_the_conversation(): void
    {
        $this->freezeSecond();
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        $tutorMessage = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor]);
        Sanctum::actingAs($learner);
        $material = $this->material('Gait cycle', flashcards: 2, withQuestions: true);
        // The schema can't enforce one right answer, so a multiple choice question with two is left out.
        $brokenQuestion = $material['questions'][0];
        $brokenQuestion['options'][1]['is_correct'] = true;
        $material['questions'][] = $brokenQuestion;
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$material]])]);

        // The sync queue in tests runs the job straight after the save.
        $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.'],
        ]))->assertCreated();

        $flashcard = Flashcard::first();
        $this->assertSame(2, Flashcard::count());
        $this->assertSame(ContentOrigin::Ai, $flashcard->origin);
        $this->assertSame($tutorMessage->id, $flashcard->source_message_id);
        $this->assertTrue($flashcard->due_at->equalTo(now()));
        $this->assertTrue($flashcard->user->is($learner));

        $multipleChoice = Question::where('type', QuestionType::MultipleChoice)->sole();
        $this->assertSame([1, 2, 3], $multipleChoice->options()->orderBy('position')->pluck('position')->all());
        $this->assertNull($multipleChoice->model_answer);
        $this->assertSame('Stance comes first.', Question::where('type', QuestionType::Open)->sole()->model_answer);
        $this->assertSame(GenerationStatus::Ready, Subtopic::sole()->generation_status);

        Http::assertSent(function (Request $request) use ($learner): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request['model'] === config('ai.levels.medium.generation_model')
                && $request['reasoning_effort'] === config('ai.levels.medium.generation_reasoning_effort')
                && $request['safety_identifier'] === $learner->aiSafetyIdentifier()
                && $input['topic'] === ['title' => 'Biomechanics of running', 'goal' => 'Explain the running gait to my athletes']
                && $input['subtopics'][0] === ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.', 'existing_items' => []];
        });
    }

    public function test_saving_a_continued_topic_adds_to_it_and_only_queues_what_is_new(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Old goal']);
        $changedSubtopic = Subtopic::factory()->for($topic)->create(['title' => 'Gait cycle', 'content' => 'Stance phase.', 'position' => 1]);
        $unchangedSubtopic = Subtopic::factory()->for($topic)->create(['title' => 'Ground reaction force', 'content' => 'Unchanged notes.', 'position' => 2]);
        $conversation = $this->openConversation($learner, $topic);
        Sanctum::actingAs($learner);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'gait cycle', 'content' => 'Stance phase. Swing phase.'],
            ['title' => 'Ground reaction force', 'content' => 'Unchanged notes.'],
            ['title' => 'Cadence', 'content' => 'Steps per minute.'],
        ]));

        $response->assertCreated()->assertJsonPath('added', ['subtopics' => 1]);

        $this->assertSame(1, Topic::count());
        $this->assertSame('Explain the running gait to my athletes', $topic->fresh()->goal);
        $this->assertSame(['Gait cycle', 'Ground reaction force', 'Cadence'], $topic->subtopics()->orderBy('position')->pluck('title')->all());
        $this->assertSame('Stance phase. Swing phase.', $changedSubtopic->fresh()->content);
        $this->assertSame(GenerationStatus::Ready, $unchangedSubtopic->fresh()->generation_status);

        Queue::assertPushed(GenerateSubtopicMaterial::class, 2);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($changedSubtopic));
        Queue::assertNotPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($unchangedSubtopic));
    }

    public function test_saving_without_new_notes_queues_nothing(): void
    {
        Queue::fake();
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($topic)->create(['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']);
        $conversation = $this->openConversation($learner, $topic);
        Sanctum::actingAs($learner);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.'],
        ]));

        $response->assertCreated()->assertJsonPath('added', ['subtopics' => 0]);
        $this->assertTrue($conversation->fresh()->isSaved());
        Queue::assertNothingPushed();
    }

    public function test_a_topic_needs_a_complete_smart_goal_and_a_subtopic(): void
    {
        $conversation = $this->openConversation($learner = $this->learnerWithAiConfigured());
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", ['title' => 'Running'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'goal' => 'Set a goal for this topic first.',
                'subtopics' => 'Add at least one subtopic.',
            ]);

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(
            goal: ['description' => 'Explain gait', 'deadline' => now()->toDateString(), 'recall_percentage' => 101],
            subtopics: [['title' => 'Cadence', 'content' => 'Steps.'], ['title' => 'cadence', 'content' => 'More steps.']],
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'goal.deadline' => 'Pick a deadline after today.',
                'goal.recall_percentage' => 'Choose how much you want to remember, from 1 to 100%.',
                'subtopics.1.title' => 'Two subtopics have the same name. Rename or remove one.',
            ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('topics', 0);
    }

    public function test_a_saved_conversation_cannot_be_saved_again(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $savedConversation = StudySession::factory()->for($learner)->create(['ended_at' => now()]);
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/conversations/{$savedConversation->id}/save", $this->reviewedDraft())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['conversation' => 'This conversation is saved. Use "Continue learning" to add to it.']);
        Http::assertNothingSent();
    }

    public function test_someone_elses_conversation_cannot_be_saved(): void
    {
        $someoneElsesConversation = $this->openConversation(User::factory()->create());
        Sanctum::actingAs($this->learnerWithAiConfigured());
        Http::fake();

        $this->postJson("/api/v1/conversations/{$someoneElsesConversation->id}/save", $this->reviewedDraft())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_new_topic_goes_in_the_category_the_learner_picked_or_named(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$this->material('Gait cycle', flashcards: 1)]])]);
        $subtopics = [['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']];

        $byId = $this->openConversation($learner);
        $this->postJson("/api/v1/conversations/{$byId->id}/save", [...$this->reviewedDraft(subtopics: $subtopics), 'category_name' => null, 'category_id' => $science->id])
            ->assertCreated()
            ->assertJsonPath('topic.category', ['id' => $science->id, 'name' => 'Science']);

        $byExistingName = $this->openConversation($learner);
        $this->postJson("/api/v1/conversations/{$byExistingName->id}/save", [...$this->reviewedDraft(subtopics: $subtopics), 'category_name' => 'sCIENCE'])
            ->assertCreated()
            ->assertJsonPath('topic.category.id', $science->id);

        $byNewName = $this->openConversation($learner);
        $this->postJson("/api/v1/conversations/{$byNewName->id}/save", $this->reviewedDraft(subtopics: $subtopics))
            ->assertCreated()
            ->assertJsonPath('topic.category.name', 'Sport');

        $this->assertSame(['Science', 'Sport'], $learner->categories()->orderBy('id')->pluck('name')->all());
        $this->assertSame('Sport', $byNewName->fresh()->draft['category']);
    }

    public function test_a_new_topic_needs_a_category_of_your_own(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $conversation = $this->openConversation($learner);
        $someoneElsesCategory = Category::factory()->create();
        Sanctum::actingAs($learner);
        Http::fake();

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", [...$this->reviewedDraft(), 'category_name' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'Pick a category for this topic.']);

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", [...$this->reviewedDraft(), 'category_name' => null, 'category_id' => $someoneElsesCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => "We couldn't find that category. Pick one from the list."]);

        $this->postJson("/api/v1/conversations/{$conversation->id}/save", [...$this->reviewedDraft(), 'category_id' => $someoneElsesCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'Pick a category or name a new one, not both.']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('topics', 0);
    }

    public function test_a_continued_topic_keeps_its_category_unless_another_is_picked(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $topic = Topic::factory()->for($learner)->create();
        $originalCategory = $topic->category;
        Subtopic::factory()->for($topic)->create(['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']);
        Sanctum::actingAs($learner);
        Http::fake();
        $unchangedSubtopics = [['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']];

        $kept = $this->openConversation($learner, $topic);
        $this->postJson("/api/v1/conversations/{$kept->id}/save", [...$this->reviewedDraft(subtopics: $unchangedSubtopics), 'category_name' => null])
            ->assertCreated()
            ->assertJsonPath('topic.category.id', $originalCategory->id);

        $changed = $this->openConversation($learner, $topic);
        $this->postJson("/api/v1/conversations/{$changed->id}/save", $this->reviewedDraft(subtopics: $unchangedSubtopics))
            ->assertCreated()
            ->assertJsonPath('topic.category.name', 'Sport');
    }

    private function openConversation(User $learner, ?Topic $topic = null): StudySession
    {
        return StudySession::factory()->for($learner)->create(['topic_id' => $topic?->id, 'ended_at' => null]);
    }

    /**
     * @param  array<string, mixed>|null  $goal
     * @param  array<int, array{title: string, content: string}>|null  $subtopics
     * @return array<string, mixed>
     */
    private function reviewedDraft(?array $goal = null, ?array $subtopics = null): array
    {
        return [
            'title' => 'Biomechanics of running',
            'category_name' => 'Sport',
            'goal' => $goal ?? [
                'description' => 'Explain the running gait to my athletes',
                'deadline' => now()->addMonth()->toDateString(),
                'recall_percentage' => 80,
            ],
            'subtopics' => $subtopics ?? [
                ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.'],
                ['title' => 'Ground reaction force', 'content' => 'The force the ground pushes back with.'],
            ],
        ];
    }

    /**
     * Generated material for one subtopic, as the AI returns it.
     *
     * @return array<string, mixed>
     */
    private function material(string $title, int $flashcards, bool $withQuestions = false): array
    {
        return [
            'title' => $title,
            'flashcards' => array_map(fn (int $number): array => [
                'front' => "Question {$number} about {$title}?",
                'back' => "Answer {$number}.",
            ], $flashcards === 0 ? [] : range(1, $flashcards)),
            'questions' => $withQuestions ? [
                [
                    'type' => 'multiple_choice',
                    'prompt' => 'Which phase comes first?',
                    'model_answer' => 'ignored for multiple choice',
                    'options' => [
                        ['label' => 'Stance', 'is_correct' => true, 'explanation' => 'Your foot lands first.'],
                        ['label' => 'Swing', 'is_correct' => false, 'explanation' => 'Swing follows stance.'],
                        ['label' => 'Flight', 'is_correct' => false, 'explanation' => null],
                    ],
                ],
                ['type' => 'open', 'prompt' => 'Describe the gait cycle.', 'model_answer' => 'Stance comes first.', 'options' => []],
            ] : [],
        ];
    }
}
