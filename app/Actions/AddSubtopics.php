<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds several subtopics to a topic at once, at the end, as the learner kept them from SuggestSubtopics.
 * Flashcards and questions are generated for the ones with notes in one AI call first, so a failed
 * generation saves nothing (specs/topic-from-source.md).
 */
class AddSubtopics
{
    public function __construct(
        private GeneratePracticeMaterial $generatePracticeMaterial,
        private StorePracticeMaterial $storePracticeMaterial,
        private SummariseSubtopicProgress $summariseSubtopicProgress,
    ) {}

    /**
     * @param  array<int, array{title: string, description: ?string, notes: ?string}>  $subtopics  titles unique among themselves
     * @return array{subtopics: array<int, array{subtopic: Subtopic, progress: array<string, mixed>}>, added_flashcards: int, added_questions: int}
     *
     * @throws ValidationException when a title is already in the topic or the subtopics don't fit.
     * @throws AiRequestFailed
     */
    public function handle(Topic $topic, array $subtopics): array
    {
        $this->ensureSubtopicsFitTopic($topic, $subtopics);

        $subtopicsWithNotes = array_filter($subtopics, fn (array $subtopic): bool => $subtopic['notes'] !== null);

        $material = $subtopicsWithNotes === [] ? [] : $this->generatePracticeMaterial->handle(
            learner: $topic->user,
            topic: ['title' => $topic->title, 'goal' => $topic->goal ?? ''],
            subtopics: array_map(fn (array $subtopic): array => [
                'title' => $subtopic['title'],
                'content' => $subtopic['notes'],
                'existing_items' => [],
            ], array_values($subtopicsWithNotes)),
        );

        // Material comes back in the order it was asked for, so it is keyed by the request's index here.
        $materialPerIndex = array_combine(array_keys($subtopicsWithNotes), $material);

        $result = DB::transaction(function () use ($topic, $subtopics, $materialPerIndex): array {
            $nextPosition = ($topic->subtopics()->max('position') ?? 0) + 1;
            $addedSubtopics = [];
            $addedFlashcards = 0;
            $addedQuestions = 0;

            foreach ($subtopics as $index => $subtopic) {
                $created = $topic->subtopics()->create([
                    'title' => $subtopic['title'],
                    'description' => $subtopic['description'],
                    'content' => $subtopic['notes'],
                    'position' => $nextPosition++,
                ]);

                if (isset($materialPerIndex[$index])) {
                    $added = $this->storePracticeMaterial->handle($created, $topic->user_id, $materialPerIndex[$index], sourceMessageId: null);
                    $addedFlashcards += $added['flashcards'];
                    $addedQuestions += $added['questions'];
                }

                $addedSubtopics[] = $created;
            }

            return [
                'subtopics' => $addedSubtopics,
                'added_flashcards' => $addedFlashcards,
                'added_questions' => $addedQuestions,
            ];
        });

        $learner = $topic->user;
        $result['subtopics'] = array_map(function (Subtopic $subtopic) use ($learner): array {
            $subtopic->load(SummariseSubtopicProgress::relations($learner));

            return [
                'subtopic' => $subtopic,
                'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
            ];
        }, $result['subtopics']);

        return $result;
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
