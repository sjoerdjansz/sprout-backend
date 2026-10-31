<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One call to OpenAI's Chat Completions API that must answer with JSON matching a schema.
 * This is the only place that talks to the AI provider.
 */
class RequestAiCompletion
{
    public const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param  array<int, array{role: string, content: string}>  $messages  the conversation after the instructions
     * @param  array<string, mixed>  $schema  JSON Schema the answer must follow (strict mode)
     * @param  string  $safetyIdentifier  the learner's User::aiSafetyIdentifier()
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
        string $safetyIdentifier,
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
                    'safety_identifier' => $safetyIdentifier,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('The AI provider could not be reached.', ['model' => $model, 'error' => $exception->getMessage()]);

            throw AiRequestFailed::unavailable();
        }

        $this->ensureSuccessful($response, $model);

        return [
            'content' => $this->decodeAnswer($response, $model),
            'model' => (string) $response->json('model', $model),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }

    /**
     * @throws AiRequestFailed
     */
    private function ensureSuccessful(Response $response, string $model): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            throw AiRequestFailed::keyRejected();
        }

        // OpenAI uses 429 both for "slow down" and for an account without credit.
        if ($response->status() === 429 && $response->json('error.type') === 'insufficient_quota') {
            throw AiRequestFailed::outOfCredit();
        }

        Log::warning('The AI provider answered with an error.', [
            'model' => $model,
            'status' => $response->status(),
            'error' => $response->json('error.message'),
        ]);

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
