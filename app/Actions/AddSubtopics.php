<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds several subtopics to a topic at once, at the end, as the learner kept them from SuggestSubtopics
 * (specs/topic-from-source.md). The ones with notes get their flashcards and questions in the background,
 * a job per subtopic (specs/background-generation.md).
 */
class AddSubtopics
{
    public function __construct(
        private QueueMaterialGeneration $queueMaterialGeneration,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @param  array<int, array{title: string, description: ?string, notes: ?string}>  $subtopics  titles unique among themselves
     * @return array<int, array{subtopic: Subtopic, progress: array<string, mixed>}> the added subtopics, in order
     *
     * @throws ValidationException when a title is already in the topic or the subtopics don't fit.
     */
    public function handle(Topic $topic, array $subtopics): array
    {
        $this->ensureSubtopicsFitTopic($topic, $subtopics);

        $addedSubtopics = DB::transaction(function () use ($topic, $subtopics): array {
            $nextPosition = ($topic->subtopics()->max('position') ?? 0) + 1;
            $addedSubtopics = [];

            foreach ($subtopics as $subtopic) {
                $created = $topic->subtopics()->create([
                    'title' => $subtopic['title'],
                    'description' => $subtopic['description'],
                    'content' => $subtopic['notes'],
                    'position' => $nextPosition++,
                ]);

                if ($subtopic['notes'] !== null) {
                    $this->queueMaterialGeneration->handle($created);
                }

                $addedSubtopics[] = $created;
            }

            return $addedSubtopics;
        });

        $learner = $topic->user;

        return array_map(function (Subtopic $subtopic) use ($learner): array {
            $subtopic->load(SummariseSubtopicProgress::relations($learner));

            return [
                'subtopic' => $subtopic,
                'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
            ];
        }, $addedSubtopics);
    }

    /**
     * Like EnsureSubtopicFitsTopic, for all of them at once, so the learner sees every clash in one go.
     *
     * @param  array<int, array{title: string, description: ?string, notes: ?string}>  $subtopics
     *
     * @throws ValidationException
     */
    private function ensureSubtopicsFitTopic(Topic $topic, array $subtopics): void
    {
        $existingTitles = $topic->subtopics()->pluck('title')->map(fn (string $title): string => Str::lower($title));
        $room = EnsureSubtopicFitsTopic::MAX_SUBTOPICS - $existingTitles->count();
        $errors = [];

        foreach ($subtopics as $index => $subtopic) {
            if ($existingTitles->contains(Str::lower($subtopic['title']))) {
                $errors["subtopics.{$index}.title"] = EnsureSubtopicFitsTopic::DUPLICATE_TITLE_MESSAGE;
            }
        }

        if (count($subtopics) > $room) {
            $errors['subtopics'] = $room <= 0
                ? SuggestSubtopics::TOPIC_FULL_MESSAGE
                : "This topic has room for {$room} more ".Str::plural('subtopic', $room).'. Leave some out.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
