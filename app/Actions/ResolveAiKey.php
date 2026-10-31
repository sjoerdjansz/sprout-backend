<?php

namespace App\Actions;

use App\Exceptions\AiRequestFailed;
use Illuminate\Support\Facades\Log;

/**
 * Which API key the AI calls use: Sprout's own OpenAI key (OPENAI_API_KEY). Learners don't bring a key
 * (specs/settings.md, 2026-10-31). Where the key comes from may change again, which is why this is the
 * only place deciding it.
 */
class ResolveAiKey
{
    /**
     * @throws AiRequestFailed when no key is configured: that is Sprout's problem, so the learner gets a 503.
     */
    public function handle(): string
    {
        $apiKey = config('services.openai.key');

        if (! is_string($apiKey) || $apiKey === '') {
            Log::error('OPENAI_API_KEY is not set, so the AI can\'t be called.');

            throw AiRequestFailed::unavailable();
        }

        return $apiKey;
    }
}
