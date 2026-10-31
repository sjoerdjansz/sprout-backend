<?php

namespace Tests\Feature;

use App\Actions\QueueMaterialGeneration;
use App\Enums\GenerationStatus;
use App\Exceptions\AiRequestFailed;
use App\Jobs\GenerateSubtopicMaterial;
use App\Models\Flashcard;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesOpenAi;
use Tests\TestCase;

class GenerateSubtopicMaterialTest extends TestCase
{
    use FakesOpenAi;
    use RefreshDatabase;

    public function test_queueing_marks_the_subtopic_as_generating(): void
    {
        Queue::fake();
        $subtopic = Subtopic::factory()->create();

        app(QueueMaterialGeneration::class)->handle($subtopic, sourceMessageId: 7);

        $this->assertSame(GenerationStatus::Generating, $subtopic->fresh()->generation_status);
        Queue::assertPushed(GenerateSubtopicMaterial::class, fn (GenerateSubtopicMaterial $job): bool => $job->subtopic->is($subtopic) && $job->sourceMessageId === 7);
    }

    public function test_the_job_makes_material_from_the_notes_as_they_are_now_and_marks_the_subtopic_ready(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = $this->generatingSubtopic($learner, notes: 'Old notes.');
        Flashcard::factory()->for($learner)->for($subtopic->topic)->for($subtopic)->create(['front' => 'What is cadence?']);
        $job = new GenerateSubtopicMaterial($subtopic, sourceMessageId: null);
        $subtopic->update(['content' => 'Steps per minute.']);
        Http::fake(['api.openai.com/*' => $this->openAiAnswer(['subtopics' => [$this->material('Cadence', flashcards: 2)]])]);

        dispatch($job);

        $subtopic->refresh();
        $this->assertSame(GenerationStatus::Ready, $subtopic->generation_status);
        $this->assertSame(3, $subtopic->flashcards()->count());
        $this->assertSame(1, $subtopic->questions()->count());
        Http::assertSent(function (Request $request) use ($learner): bool {
            $input = json_decode($request['messages'][1]['content'], true);

            return $request['model'] === config('ai.levels.medium.generation_model')
                && $request['safety_identifier'] === $learner->aiSafetyIdentifier()
                && $input['topic'] === ['title' => 'Running', 'goal' => 'Coach better']
                && $input['subtopics'][0] === ['title' => 'Cadence', 'content' => 'Steps per minute.', 'existing_items' => ['What is cadence?']];
        });
    }

    public function test_a_subtopic_without_notes_is_ready_without_an_ai_call(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = $this->generatingSubtopic($learner, notes: null);
        Http::fake();

        dispatch(new GenerateSubtopicMaterial($subtopic));

        $this->assertSame(GenerationStatus::Ready, $subtopic->fresh()->generation_status);
        Http::assertNothingSent();
    }

    public function test_when_every_try_fails_the_subtopic_is_marked_as_failed_and_nothing_is_stored(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = $this->generatingSubtopic($learner, notes: 'Steps per minute.');
        Http::fake(['api.openai.com/*' => $this->openAiError(500, 'server_error')]);

        // The sync queue in tests fails a job on its first exception, like a real worker after its last try.
        try {
            dispatch(new GenerateSubtopicMaterial($subtopic));
            $this->fail('The job should have failed.');
        } catch (AiRequestFailed) {
        }

        $this->assertSame(GenerationStatus::Failed, $subtopic->fresh()->generation_status);
        $this->assertSame(0, $subtopic->flashcards()->count());
    }

    public function test_a_subtopic_deleted_before_its_job_runs_drops_the_job(): void
    {
        $learner = $this->learnerWithAiConfigured();
        $subtopic = $this->generatingSubtopic($learner, notes: 'Steps per minute.');
        $job = new GenerateSubtopicMaterial($subtopic);
        $subtopic->delete();
        Http::fake();

        dispatch($job);

        Http::assertNothingSent();
    }

    public function test_the_job_retries_a_failed_call_and_waits_longer_each_time(): void
    {
        Queue::connection('database')->push(new GenerateSubtopicMaterial(Subtopic::factory()->create()));

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame(3, $payload['maxExceptions']);
        $this->assertSame('15,60', $payload['backoff']);
        $this->assertGreaterThan(config('ai.generation.timeout_seconds'), $payload['timeout']);
        $this->assertGreaterThan($payload['timeout'], config('queue.connections.database.retry_after'));
    }

    private function generatingSubtopic(User $learner, ?string $notes): Subtopic
    {
        $topic = Topic::factory()->for($learner)->create(['title' => 'Running', 'goal' => 'Coach better']);

        return Subtopic::factory()->for($topic)->create([
            'title' => 'Cadence',
            'content' => $notes,
            'generation_status' => GenerationStatus::Generating,
        ]);
    }

    /**
     * Generated material for one subtopic, as the AI returns it.
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
                ['type' => 'open', 'prompt' => "Explain {$title}.", 'model_answer' => 'In your own words.', 'options' => []],
            ],
        ];
    }
}
