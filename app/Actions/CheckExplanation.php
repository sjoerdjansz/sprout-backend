<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Exceptions\AiRequestFailed;
use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Lets the AI look for the gaps in an Explain it explanation and give feedback on it, against the
 * subtopic's key points, notes and cards. Stores nothing; ExplainSubtopic saves the result.
 */
class CheckExplanation
{
    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * @param  ?Answer  $firstVersion  the checked first version, when this is the simpler second one
     * @return array{score: int, feedback: string, gaps: array<int, array{text: string, note: string}>}
     *
     * @throws AiRequestFailed
     */
    public function handle(User $learner, Subtopic $subtopic, string $prompt, ?string $keyPoints, string $explanation, ?Answer $firstVersion): array
    {
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        // The chat model and its limits: the learner waits for the feedback in the middle of a session.
        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->chatModel(),
            reasoningEffort: $level->chatReasoningEffort(),
            instructions: file_get_contents(resource_path('prompts/explain-check.md')),
            messages: [
                ['role' => 'user', 'content' => json_encode([
                    'concept' => $subtopic->title,
                    'prompt' => $prompt,
                    'key_points' => $keyPoints,
                    'notes' => $subtopic->content,
                    'flashcards' => $subtopic->flashcards
                        ->whereNull('suspended_at')
                        ->map(fn (Flashcard $flashcard): array => ['front' => $flashcard->front, 'back' => $flashcard->back])
                        ->values()
                        ->all(),
                    'first_version' => $firstVersion === null ? null : [
                        'explanation' => $firstVersion->response,
                        'feedback' => $firstVersion->feedback,
                    ],
                    'explanation' => $explanation,
                ])],
            ],
            schemaName: 'explanation_check',
            schema: self::checkSchema(),
            maxCompletionTokens: config('ai.chat.max_completion_tokens'),
            timeoutSeconds: config('ai.chat.timeout_seconds'),
            learner: $learner,
            kind: AiCallKind::ExplainFeedback,
        );

        return [
            // The schema can't limit the range, so a score outside 0–100 is brought back into it.
            'score' => max(0, min(100, (int) $completion['content']['score'])),
            'feedback' => $completion['content']['feedback'],
            'gaps' => $this->gapsFoundIn($explanation, $completion['content']['gaps']),
        ];
    }

    /**
     * The frontend highlights each gap in the learner's text, so a gap whose text isn't quoted exactly
     * can't be shown; it is left out rather than pointing at nothing.
     *
     * @param  array<int, array{text: string, note: string}>  $gaps
     * @return array<int, array{text: string, note: string}>
     */
    private function gapsFoundIn(string $explanation, array $gaps): array
    {
        $found = [];

        foreach ($gaps as $gap) {
            if ($gap['text'] === '' || ! str_contains($explanation, $gap['text'])) {
                Log::warning('Left out an Explain it gap that isn\'t quoted from the explanation.', ['gap' => $gap['text']]);

                continue;
            }

            $found[] = ['text' => $gap['text'], 'note' => $gap['note']];
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'feedback', 'gaps'],
            'properties' => [
                'score' => ['type' => 'integer'],
                'feedback' => ['type' => 'string'],
                'gaps' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'note'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'note' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
