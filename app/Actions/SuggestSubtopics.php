<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Exceptions\AiRequestFailed;
use App\Models\Topic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads a topic's description and notes (its source) and suggests the subtopics it falls into, each with
 * the part of the source it covers. Stores nothing: the learner reviews the suggestions and adds the ones
 * they keep with AddSubtopics (specs/topic-from-source.md).
 */
class SuggestSubtopics
{
    public const NOTHING_TO_READ_MESSAGE = 'Add a description or notes to this topic first, so Sprout has something to read.';

    public const TOPIC_FULL_MESSAGE = 'This topic has 30 subtopics, the most it can have.';

    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * Suggestions the topic doesn't have yet (by title, in any capitalisation), at most as many as still fit.
     *
     * @return array<int, array{title: string, description: ?string, notes: ?string}>
     *
     * @throws ValidationException when the topic has nothing to read or is full.
     * @throws AiRequestFailed
     */
    public function handle(Topic $topic): array
    {
        if (blank($topic->description) && blank($topic->notes)) {
            throw ValidationException::withMessages(['topic' => self::NOTHING_TO_READ_MESSAGE]);
        }

        $existingTitles = $topic->subtopics()->orderBy('position')->pluck('title');
        $room = EnsureSubtopicFitsTopic::MAX_SUBTOPICS - $existingTitles->count();

        if ($room <= 0) {
            throw ValidationException::withMessages(['topic' => self::TOPIC_FULL_MESSAGE]);
        }

        $learner = $topic->user;
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        // Splitting a long source well needs the stronger model, and copying it back needs generation's token budget.
        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->generationModel(),
            instructions: file_get_contents(resource_path('prompts/subtopic-suggestions.md')),
            messages: [
                ['role' => 'user', 'content' => json_encode([
                    'topic' => [
                        'title' => $topic->title,
                        'description' => $topic->description ?? '',
                        'notes' => $topic->notes ?? '',
                    ],
                    'existing_subtopics' => $existingTitles->all(),
                    'max_suggestions' => $room,
                ])],
            ],
            schemaName: 'subtopic_suggestions',
            schema: self::suggestionsSchema(),
            maxCompletionTokens: config('ai.generation.max_completion_tokens'),
            timeoutSeconds: config('ai.generation.timeout_seconds'),
            learner: $learner,
            kind: AiCallKind::SubtopicSuggestions,
        );

        return $this->newSuggestions($completion['content']['suggestions'], $existingTitles->all(), $room);
    }

    /**
     * The prompt asks the AI to skip existing subtopics and stay under the maximum; this makes sure of it.
     *
     * @param  array<int, array{title: string, description: string, notes: string}>  $suggestions
     * @param  array<int, string>  $existingTitles
     * @return array<int, array{title: string, description: ?string, notes: ?string}>
     *
     * @throws AiRequestFailed when a suggestion wouldn't fit in a subtopic.
     */
    private function newSuggestions(array $suggestions, array $existingTitles, int $room): array
    {
        $takenTitles = array_map(fn (string $title): string => Str::lower($title), $existingTitles);
        $newSuggestions = [];

        foreach ($suggestions as $suggestion) {
            $title = trim($suggestion['title']);

            if ($title === '' || in_array(Str::lower($title), $takenTitles, true)) {
                continue;
            }

            // A suggestion has to pass POST /topics/{id}/subtopics as it is, and the learner can't shorten its notes
            // in the review. A part that is too long usually comes out right when asked again.
            if (Str::length($title) > 255 || Str::length($suggestion['description']) > 1000 || Str::length($suggestion['notes']) > 10000) {
                Log::warning('The AI suggested a subtopic that is too long to add.', [
                    'title_length' => Str::length($title),
                    'notes_length' => Str::length($suggestion['notes']),
                ]);

                throw AiRequestFailed::unavailable();
            }

            $takenTitles[] = Str::lower($title);
            $newSuggestions[] = [
                'title' => $title,
                'description' => trim($suggestion['description']) === '' ? null : trim($suggestion['description']),
                'notes' => trim($suggestion['notes']) === '' ? null : trim($suggestion['notes']),
            ];
        }

        return array_slice($newSuggestions, 0, $room);
    }

    /**
     * @return array<string, mixed>
     */
    private static function suggestionsSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['suggestions'],
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'description', 'notes'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
