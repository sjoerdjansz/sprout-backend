<?php

namespace Tests\Concerns;

use App\Models\AiCredential;
use App\Models\User;
use App\Models\UserSetting;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Fake answers from OpenAI's Chat Completions API, shaped like the real ones.
 */
trait FakesOpenAi
{
    protected const LEARNER_API_KEY = 'sk-learner-test-key';

    protected function learnerWithKey(): User
    {
        $learner = User::factory()->create(['name' => 'Ada']);
        UserSetting::factory()->for($learner)->create();
        AiCredential::factory()->for($learner)->create(['encrypted_api_key' => self::LEARNER_API_KEY]);

        return $learner;
    }

    /**
     * @param  array<string, mixed>  $content  the JSON the model answers with
     */
    protected function openAiAnswer(array $content, string $model = 'gpt-5.6-terra'): PromiseInterface
    {
        return Http::response([
            'model' => $model,
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode($content), 'refusal' => null],
            ]],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 150],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $draft
     * @return array<string, mixed>
     */
    protected function tutorReply(string $reply = 'What do you already know about **biomechanics**?', ?array $draft = null): array
    {
        return [
            'reply' => $reply,
            'draft' => $draft ?? [
                'title' => 'Biomechanics',
                'category' => null,
                'goal' => ['description' => null, 'deadline' => null, 'recall_percentage' => null],
                'subtopics' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function openAiError(int $status, string $type, string $message = 'Something went wrong.'): PromiseInterface
    {
        return Http::response(['error' => ['message' => $message, 'type' => $type, 'param' => null, 'code' => null]], $status);
    }
}
