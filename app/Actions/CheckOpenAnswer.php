<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Exceptions\AiRequestFailed;
use App\Models\Question;
use App\Models\User;

/**
 * Lets the AI grade a typed answer to an open question against its model answer. Stores nothing;
 * AnswerQuestion saves the result.
 */
class CheckOpenAnswer
{
    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * @return array{score: int, is_correct: bool, feedback: string}
     *
     * @throws AiRequestFailed
     */
    public function handle(User $learner, Question $question, string $response): array
    {
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        // The chat model and its limits: the learner waits for the check in the middle of a quiz.
        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->chatModel(),
            instructions: file_get_contents(resource_path('prompts/answer-check.md')),
            messages: [
                ['role' => 'user', 'content' => json_encode([
                    'question' => $question->prompt,
                    'model_answer' => $question->model_answer,
                    'learner_answer' => $response,
                ])],
            ],
            schemaName: 'answer_check',
            schema: self::checkSchema(),
            maxCompletionTokens: config('ai.chat.max_completion_tokens'),
            timeoutSeconds: config('ai.chat.timeout_seconds'),
            learner: $learner,
            kind: AiCallKind::AnswerCheck,
        );

        return [
            // The schema can't limit the range, so a score outside 0–100 is brought back into it.
            'score' => max(0, min(100, (int) $completion['content']['score'])),
            'is_correct' => (bool) $completion['content']['is_correct'],
            'feedback' => $completion['content']['feedback'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['score', 'is_correct', 'feedback'],
            'properties' => [
                'score' => ['type' => 'integer'],
                'is_correct' => ['type' => 'boolean'],
                'feedback' => ['type' => 'string'],
            ],
        ];
    }
}
