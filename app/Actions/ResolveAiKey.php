<?php

namespace App\Actions;

use App\Enums\AiProvider;
use App\Exceptions\AiRequestFailed;
use App\Models\User;

/**
 * Which API key the learner's AI calls use. Today that is their own OpenAI key; where the key comes
 * from may change later (a business key, see VISION.md), which is why this is the only place deciding it.
 */
class ResolveAiKey
{
    /**
     * @throws AiRequestFailed when the learner has no key.
     */
    public function handle(User $user): string
    {
        $credential = $user->aiCredentials()->where('provider', AiProvider::OpenAi)->first();

        if ($credential !== null) {
            return $credential->encrypted_api_key;
        }

        // Connecting a key isn't built yet, so locally every account may use the developer's key.
        $developmentKey = config('services.openai.key');

        if (app()->isLocal() && is_string($developmentKey) && $developmentKey !== '') {
            return $developmentKey;
        }

        throw AiRequestFailed::missingKey();
    }
}
