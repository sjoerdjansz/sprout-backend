<?php

namespace Tests\Feature;

use App\Enums\ContentOrigin;
use App\Enums\MessageRole;
use App\Enums\QuestionType;
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
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class SaveConversationTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_saving_creates_the_topic_with_its_goal_subtopics_and_practice_material(): void
    {
        $this->freezeSecond();
        $learner = $this->learnerWithKey();
        $conversation = $this->openConversation($learner);
        $tutorMessage = Message::factory()->for($conversation)->create(['role' => MessageRole::Tutor]);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [
            $this->material('Gait cycle', flashcards: 2, withQuestions: true),
            $this->material('Ground reaction force', flashcards: 1),
        ]])]);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft());

        $response->assertCreated()
            ->assertJsonPath('topic.title', 'Biomechanics of running')
            ->assertJsonPath('topic.goal', [
                'description' => 'Explain the running gait to my athletes',
                'deadline' => now()->addMonth()->toDateString(),
                'recall_percentage' => 80,
            ])
            ->assertJsonPath('topic.subtopics.0.title', 'Gait cycle')
            ->assertJsonPath('topic.subtopics.1.title', 'Ground reaction force')
            ->assertJsonPath('added', ['subtopics' => 2, 'flashcards' => 3, 'questions' => 2]);

        $topic = Topic::sole();
        $this->assertTrue($topic->user->is($learner));
        $this->assertSame([1, 2], $topic->subtopics()->orderBy('position')->pluck('position')->all());
        $this->assertSame('Stance and swing phases.', $topic->subtopics()->where('title', 'Gait cycle')->value('content'));

        $flashcard = Flashcard::first();
        $this->assertSame(ContentOrigin::Ai, $flashcard->origin);
        $this->assertSame($tutorMessage->id, $flashcard->source_message_id);
        $this->assertTrue($flashcard->due_at->equalTo(now()));
        $this->assertTrue($flashcard->user->is($learner));

        $multipleChoice = Question::where('type', QuestionType::MultipleChoice)->sole();
        $this->assertSame([1, 2, 3], $multipleChoice->options()->orderBy('position')->pluck('position')->all());
        $this->assertNull($multipleChoice->model_answer);
        $this->assertSame('Stance comes first.', Question::where('type', QuestionType::Open)->sole()->model_answer);

        $conversation->refresh();
        $this->assertTrue($conversation->topic->is($topic));
        $this->assertTrue($conversation->isSaved());

        Http::assertSent(function (Request $request): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request['model'] === config('ai.levels.medium.generation_model')
                && $input['topic'] === ['title' => 'Biomechanics of running', 'goal' => 'Explain the running gait to my athletes']
                && $input['subtopics'][0] === ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.', 'existing_items' => []];
        });
    }

    public function test_saving_a_continued_topic_adds_to_it_and_only_generates_what_is_new(): void
    {
        $learner = $this->learnerWithKey();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Old goal']);
        $changedSubtopic = Subtopic::factory()->for($topic)->create(['title' => 'Gait cycle', 'content' => 'Stance phase.', 'position' => 1]);
        Subtopic::factory()->for($topic)->create(['title' => 'Ground reaction force', 'content' => 'Unchanged notes.', 'position' => 2]);
        Flashcard::factory()->for($learner)->for($topic)->for($changedSubtopic)->create(['front' => 'What is the stance phase?']);
        $conversation = $this->openConversation($learner, $topic);
        Sanctum::actingAs($learner);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [
            $this->material('Gait cycle', flashcards: 1),
            $this->material('Cadence', flashcards: 2),
        ]])]);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'gait cycle', 'content' => 'Stance phase. Swing phase.'],
            ['title' => 'Ground reaction force', 'content' => 'Unchanged notes.'],
            ['title' => 'Cadence', 'content' => 'Steps per minute.'],
        ]));

        $response->assertCreated()->assertJsonPath('added', ['subtopics' => 1, 'flashcards' => 3, 'questions' => 0]);

        $this->assertSame(1, Topic::count());
        $this->assertSame('Explain the running gait to my athletes', $topic->fresh()->goal);
        $this->assertSame(['Gait cycle', 'Ground reaction force', 'Cadence'], $topic->subtopics()->orderBy('position')->pluck('title')->all());
        $this->assertSame('Stance phase. Swing phase.', $changedSubtopic->fresh()->content);
        $this->assertSame(2, $changedSubtopic->flashcards()->count());

        Http::assertSent(function (Request $request): bool {
            $subtopics = json_decode($request['messages'][1]['content'], true)['subtopics'];

            return array_column($subtopics, 'title') === ['gait cycle', 'Cadence']
                && $subtopics[0]['existing_items'] === ['What is the stance phase?'];
        });
    }

    public function test_saving_without_new_notes_needs_no_ai_call(): void
    {
        $learner = $this->learnerWithKey();
        $topic = Topic::factory()->for($learner)->create();
        Subtopic::factory()->for($topic)->create(['title' => 'Gait cycle', 'content' => 'Stance and swing phases.']);
        $conversation = $this->openConversation($learner, $topic);
        Sanctum::actingAs($learner);
        Http::fake();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.'],
        ]));

        $response->assertCreated()->assertJsonPath('added', ['subtopics' => 0, 'flashcards' => 0, 'questions' => 0]);
        $this->assertTrue($conversation->fresh()->isSaved());
        Http::assertNothingSent();
    }

    public function test_multiple_choice_questions_without_exactly_one_right_answer_are_left_out(): void
    {
        $learner = $this->learnerWithKey();
        $conversation = $this->openConversation($learner);
        Sanctum::actingAs($learner);
        $material = $this->material('Gait cycle', flashcards: 0, withQuestions: true);
        $material['questions'][0]['options'][1]['is_correct'] = true;
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$material]])]);

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft(subtopics: [
            ['title' => 'Gait cycle', 'content' => 'Stance and swing phases.'],
        ]));

        $response->assertCreated()->assertJsonPath('added.questions', 1);
        $this->assertSame(QuestionType::Open, Question::sole()->type);
    }

    public function test_nothing_is_saved_when_generating_fails(): void
    {
        $learner = $this->learnerWithKey();
        $conversation = $this->openConversation($learner);
        Sanctum::actingAs($learner);

        foreach ([
            $this->openAiError(500, 'server_error'),
            $this->openAiAnswer(['subtopics' => [$this->material('Gait cycle', flashcards: 1)]]),
        ] as $failedGeneration) {
            Http::fake(['api.openai.com/*' => $failedGeneration]);

            $this->postJson("/api/v1/conversations/{$conversation->id}/save", $this->reviewedDraft())
                ->assertServiceUnavailable()
                ->assertJsonPath('message', "The tutor can't answer right now. Try again in a moment.");
        }

        $this->assertDatabaseCount('topics', 0);
        $this->assertDatabaseCount('flashcards', 0);
        $this->assertFalse($conversation->fresh()->isSaved());
    }

    public function test_a_topic_needs_a_complete_smart_goal_and_a_subtopic(): void
    {
        $conversation = $this->openConversation($learner = $this->learnerWithKey());
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
        $learner = $this->learnerWithKey();
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
        Sanctum::actingAs($this->learnerWithKey());
        Http::fake();

        $this->postJson("/api/v1/conversations/{$someoneElsesConversation->id}/save", $this->reviewedDraft())->assertNotFound();
        Http::assertNothingSent();
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
