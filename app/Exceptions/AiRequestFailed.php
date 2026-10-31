<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The AI couldn't answer. Sprout uses its own key, so the learner can never fix the cause: always a 503,
 * logged where it is detected. The message only differs in when trying again makes sense.
 *
 * Changed 2026-10-31: key problems used to be a 422 on `ai_key`, when learners brought their own key.
 */
class AiRequestFailed extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * A passing problem (the provider slow, busy or down, or an unusable answer): trying again soon usually works.
     */
    public static function unavailable(): self
    {
        return new self("The tutor can't answer right now. Try again in a moment.");
    }

    /**
     * Sprout's own key is missing, rejected or out of credit. Nothing works until the developer fixes it,
     * so the learner is told not to keep retrying.
     */
    public static function outOfService(): self
    {
        return new self("Sprout's AI isn't working right now. We're fixing it, so try again later.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }
}
