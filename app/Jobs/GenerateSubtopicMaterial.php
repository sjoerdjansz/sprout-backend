<?php

namespace App\Jobs;

use App\Actions\GeneratePracticeMaterial;
use App\Actions\StorePracticeMaterial;
use App\Enums\GenerationStatus;
use App\Models\Subtopic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Makes the flashcards and questions for one subtopic in the background (specs/background-generation.md),
 * so saving never waits for the AI. A failed AI call is tried again; when every try fails the subtopic
 * is marked as failed, and "Generate from sources" tries again. A deleted subtopic drops the job.
 */
#[DeleteWhenMissingModels]
#[Timeout(180)]
#[MaxExceptions(3)]
#[Backoff(15, 60)]
// Waiting for another job on the same subtopic also counts as a try, so there are more tries than exceptions.
#[Tries(10)]
class GenerateSubtopicMaterial implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int|null  $sourceMessageId  the tutor message the notes came from, when saved from a conversation
     */
    public function __construct(
        public Subtopic $subtopic,
        public ?int $sourceMessageId = null,
    ) {}

    /**
     * One job per subtopic at a time: a second one (the notes changed again) waits, so it sees what the
     * first one made and doesn't make it twice.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->subtopic->id))->releaseAfter(30)->expireAfter(240)];
    }

    /**
     * Works from the notes and existing cards as they are now, not as they were when the job was queued.
     */
    public function handle(GeneratePracticeMaterial $generatePracticeMaterial, StorePracticeMaterial $storePracticeMaterial): void
    {
        $subtopic = $this->subtopic;

        if ($subtopic->sourceText() === '') {
            $subtopic->update(['generation_status' => GenerationStatus::Ready]);

            return;
        }

        $topic = $subtopic->topic;

        $material = $generatePracticeMaterial->handle(
            learner: $topic->user,
            topic: ['title' => $topic->title, 'goal' => $topic->goal ?? ''],
            subtopics: [[
                'title' => $subtopic->title,
                'content' => $subtopic->sourceText(),
                'existing_items' => [
                    ...$subtopic->flashcards()->pluck('front'),
                    ...$subtopic->questions()->pluck('prompt'),
                ],
            ]],
        )[0];

        DB::transaction(function () use ($subtopic, $topic, $material, $storePracticeMaterial): void {
            $storePracticeMaterial->handle($subtopic, $topic->user_id, $material, $this->sourceMessageId);
            $subtopic->update(['generation_status' => GenerationStatus::Ready]);
        });
    }

    /**
     * Every try failed. The cause is already logged where the AI call failed.
     */
    public function failed(?Throwable $exception): void
    {
        $this->subtopic->update(['generation_status' => GenerationStatus::Failed]);
    }
}
