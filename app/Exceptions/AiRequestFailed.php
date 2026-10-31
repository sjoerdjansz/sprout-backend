<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The tutor couldn't get an answer from the AI provider. Sprout uses its own key, so whatever the cause
 * (a key problem, no credit, the provider being down), the learner can only try again: always a 503.
 * The cause is logged where it is detected.
 *
 * Changed 2026-10-31: key problems used to be a 422 on `ai_key`, when learners brought their own key.
 */
class AiRequestFailed extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self("The tutor can't answer right now. Try again in a moment.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 503);
    }
}
