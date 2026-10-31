<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Exceptions\AiRequestFailed;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One call to OpenAI's Chat Completions API that must answer with JSON matching a schema.
 * This is the only place that talks to the AI provider, so it also logs every call that answered
 * (ai_calls), which the learner's usage is counted from.
 */
class RequestAiCompletion
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param  array<int, array{role: string, content: string}>  $messages  the conversation after the instructions
     * @param  array<string, mixed>  $schema  JSON Schema the answer must follow (strict mode)
     * @param  User  $learner  who the call is for: their safety identifier is sent, and the call counts towards their usage
     * @return array{content: array<string, mixed>, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws AiRequestFailed when the key doesn't work or no usable answer came back.
     */
    public function handle(
        string $apiKey,
        string $model,
        string $instructions,
        array $messages,
        string $schemaName,
        array $schema,
        int $maxCompletionTokens,
        int $timeoutSeconds,
        User $learner,
        AiCallKind $kind,
    ): array {
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeoutSeconds)
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'max_completion_tokens' => $maxCompletionTokens,
                    'messages' => [
                        ['role' => 'developer', 'content' => $instructions],
                        ...$messages,
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
                    ],
                    'safety_identifier' => $learner->aiSafetyIdentifier(),
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('The AI provider could not be reached.', ['model' => $model, 'error' => $exception->getMessage()]);

            throw AiRequestFailed::unavailable();
        }

        $this->ensureSuccessful($response, $model);

        $completion = [
            'content' => $this->decodeAnswer($response, $model),
            'model' => (string) $response->json('model', $model),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];

        $learner->aiCalls()->create([
            'kind' => $kind,
            'model' => $completion['model'],
            'input_tokens' => $completion['input_tokens'],
            'output_tokens' => $completion['output_tokens'],
        ]);

        return $completion;
    }

    /**
     * @throws AiRequestFailed
     */
    private function ensureSuccessful(Response $response, string $model): void
    {
        if ($response->successful()) {
            return;
        }

        $context = [
            'model' => $model,
            'status' => $response->status(),
            'error' => $response->json('error.message'),
        ];

        // A rejected key or an account without credit (OpenAI uses 429 for that too) breaks the AI for every
        // learner until someone fixes Sprout's key, so it is an error rather than a warning.
        if ($response->status() === 401 || $response->json('error.type') === 'insufficient_quota') {
            Log::error('The AI provider rejected Sprout\'s key or the account has no credit.', $context);

            throw AiRequestFailed::outOfService();
        }

        Log::warning('The AI provider answered with an error.', $context);

        throw AiRequestFailed::unavailable();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiRequestFailed
     */
    private function decodeAnswer(Response $response, string $model): array
    {
        $finishReason = $response->json('choices.0.finish_reason');
        $answer = json_decode((string) $response->json('choices.0.message.content'), associative: true);

        // A cut-off or refused answer isn't valid JSON for the schema; asking again usually works.
        if ($finishReason !== 'stop' || ! is_array($answer)) {
            Log::warning('The AI provider gave no usable answer.', [
                'model' => $model,
                'finish_reason' => $finishReason,
                'refusal' => $response->json('choices.0.message.refusal'),
            ]);

            throw AiRequestFailed::unavailable();
        }

        return $answer;
    }
}
