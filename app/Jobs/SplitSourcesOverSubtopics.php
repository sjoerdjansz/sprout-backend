<?php

namespace App\Jobs;

use App\Actions\QueueMaterialGeneration;
use App\Actions\SaveSubtopicSource;
use App\Actions\SplitMaterialOverSubtopics;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * After a conversation with files is saved, gives each subtopic its part of every file as a source of its own
 * and then queues the flashcards and questions (specs/chat-attachments.md, specs/subtopic-sources.md). One job
 * per save: every file is read before anything is written. When every try fails, the material is still made,
 * from the sources the subtopics already have.
 */
#[DeleteWhenMissingModels]
#[Timeout(180)]
#[MaxExceptions(3)]
#[Backoff(15, 60)]
// Waiting for another save on the same topic also counts as a try, so there are more tries than exceptions.
#[Tries(10)]
class SplitSourcesOverSubtopics implements ShouldQueue
{
    use Queueable;

    /** A file source stays within the size of the other sources (SubtopicRequest's notes). */
    public const MAX_SOURCE_CHARACTERS = 10_000;

    /**
     * @param  array<int, int>  $materialIds  the files the save linked to the topic
     * @param  array<int, int>  $subtopicIdsNeedingMaterial  new subtopics and those whose notes changed in the save
     * @param  int|null  $sourceMessageId  the tutor message the notes came from
     */
    public function __construct(
        public Topic $topic,
        public array $materialIds,
        public array $subtopicIdsNeedingMaterial,
        public ?int $sourceMessageId = null,
    ) {}

    /**
     * One save per topic at a time, so two of them never write the same sources at once.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("split-sources-{$this->topic->id}"))->releaseAfter(30)->expireAfter(240)];
    }

    public function handle(
        SplitMaterialOverSubtopics $splitMaterialOverSubtopics,
        SaveSubtopicSource $saveSubtopicSource,
        QueueMaterialGeneration $queueMaterialGeneration,
    ): void {
        $subtopics = $this->topic->subtopics()->with('sources.material')->orderBy('position')->get();
        $materials = Material::whereKey($this->materialIds)
            ->where('topic_id', $this->topic->id)
            ->where('status', MaterialStatus::Ready)
            ->orderBy('id')
            ->get();

        $excerptsPerMaterial = $materials->mapWithKeys(fn (Material $material): array => [
            $material->id => $splitMaterialOverSubtopics->handle($this->topic, $material, $subtopics),
        ]);

        DB::transaction(function () use ($subtopics, $materials, $excerptsPerMaterial, $saveSubtopicSource, $queueMaterialGeneration): void {
            $subtopicIdsWithNewSources = [];

            foreach ($subtopics as $subtopic) {
                foreach ($materials as $material) {
                    $blocks = $excerptsPerMaterial[$material->id][$subtopic->id] ?? [];

                    // Written as a whole per file, so a retry replaces the part rather than adding it twice.
                    if ($blocks !== [] && $saveSubtopicSource->handle($subtopic, SourceType::File, $this->fittingPart($blocks), $material)) {
                        $subtopicIdsWithNewSources[] = $subtopic->id;
                    }
                }
            }

            $this->queueMaterial($queueMaterialGeneration, [...$this->subtopicIdsNeedingMaterial, ...$subtopicIdsWithNewSources]);
        });
    }

    /**
     * Every try failed. The subtopics the save was for still get their material, from the notes they have.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('The files of a saved conversation could not be split over its subtopics.', [
            'topic_id' => $this->topic->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->queueMaterial(app(QueueMaterialGeneration::class), $this->subtopicIdsNeedingMaterial);
    }

    /**
     * The blocks that fit in one source, in order. A part that doesn't fit is cut at a block: the whole file
     * stays with the topic.
     *
     * @param  array<int, string>  $blocks
     */
    private function fittingPart(array $blocks): string
    {
        $part = '';

        foreach ($blocks as $block) {
            $longer = $part === '' ? $block : $part."\n\n".$block;

            if (Str::length($longer) > self::MAX_SOURCE_CHARACTERS) {
                Log::info('Part of an uploaded file did not fit in one source.', ['topic_id' => $this->topic->id]);

                break;
            }

            $part = $longer;
        }

        return $part;
    }

    /**
     * @param  array<int, int>  $subtopicIds
     */
    private function queueMaterial(QueueMaterialGeneration $queueMaterialGeneration, array $subtopicIds): void
    {
        Subtopic::whereKey(array_unique($subtopicIds))
            ->orderBy('position')
            ->get()
            ->each(fn (Subtopic $subtopic) => $queueMaterialGeneration->handle($subtopic, $this->sourceMessageId));
    }
}
