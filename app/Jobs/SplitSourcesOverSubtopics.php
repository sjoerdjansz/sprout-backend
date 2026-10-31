<?php

namespace App\Jobs;

use App\Actions\QueueMaterialGeneration;
use App\Actions\SplitMaterialOverSubtopics;
use App\Enums\MaterialStatus;
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
 * After a conversation with files is saved, adds each subtopic's part of those files to its notes and then
 * queues the flashcards and questions (specs/chat-attachments.md). One job per save: every file is read before
 * anything is written, so a retry never adds a part twice. When every try fails, the material is still made,
 * from the notes the subtopics already have.
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

    /** A subtopic's notes stay within what the API lets the learner save (SubtopicRequest). */
    public const MAX_NOTES_CHARACTERS = 10_000;

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
     * One save per topic at a time, so two of them never add to the same notes at once.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("split-sources-{$this->topic->id}"))->releaseAfter(30)->expireAfter(240)];
    }

    public function handle(SplitMaterialOverSubtopics $splitMaterialOverSubtopics, QueueMaterialGeneration $queueMaterialGeneration): void
    {
        $subtopics = $this->topic->subtopics()->orderBy('position')->get();
        $materials = Material::whereKey($this->materialIds)
            ->where('topic_id', $this->topic->id)
            ->where('status', MaterialStatus::Ready)
            ->orderBy('id')
            ->get();

        $excerptsPerMaterial = $materials->mapWithKeys(fn (Material $material): array => [
            $material->id => $splitMaterialOverSubtopics->handle($this->topic, $material, $subtopics),
        ]);

        DB::transaction(function () use ($subtopics, $materials, $excerptsPerMaterial, $queueMaterialGeneration): void {
            $subtopicIdsWithNewNotes = [];

            foreach ($subtopics as $subtopic) {
                $notes = (string) $subtopic->content;

                foreach ($materials as $material) {
                    $blocks = $excerptsPerMaterial[$material->id][$subtopic->id] ?? [];

                    if ($blocks !== []) {
                        $notes = $this->withExcerpt($notes, $material->original_filename, $blocks);
                    }
                }

                if ($notes !== (string) $subtopic->content) {
                    $subtopic->update(['content' => $notes]);
                    $subtopicIdsWithNewNotes[] = $subtopic->id;
                }
            }

            $this->queueMaterial($queueMaterialGeneration, [...$this->subtopicIdsNeedingMaterial, ...$subtopicIdsWithNewNotes]);
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
     * Adds the part under a heading with the file's name. A part that doesn't fit in the notes is cut at a
     * block: the whole file stays with the topic.
     *
     * @param  array<int, string>  $blocks
     */
    private function withExcerpt(string $notes, string $filename, array $blocks): string
    {
        $section = "### From {$filename}";

        foreach ($blocks as $block) {
            $longer = $section."\n\n".$block;

            if (Str::length(trim($notes."\n\n".$longer)) > self::MAX_NOTES_CHARACTERS) {
                Log::info('Part of an uploaded file did not fit in a subtopic\'s notes.', ['topic_id' => $this->topic->id]);

                break;
            }

            $section = $longer;
        }

        if ($section === "### From {$filename}") {
            return $notes;
        }

        return trim($notes."\n\n".$section);
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
