<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The tutor couldn't get an answer from the AI provider. Rendered with a message the learner can act on:
 * a key problem is a 422 on `ai_key` (fix the key), anything else a 503 (try again).
 */
class AiRequestFailed extends RuntimeException
{
    private function __construct(string $message, private readonly bool $isKeyProblem)
    {
        parent::__construct($message);
    }

    public static function missingKey(): self
    {
        return new self('Connect your AI key to start learning.', isKeyProblem: true);
    }

    public static function keyRejected(): self
    {
        return new self("Your AI key wasn't accepted. Check it in your settings and try again.", isKeyProblem: true);
    }

    public static function outOfCredit(): self
    {
        return new self('Your AI account has run out of credit. Top it up with your AI provider and try again.', isKeyProblem: true);
    }

    public static function unavailable(): self
    {
        return new self("The tutor can't answer right now. Try again in a moment.", isKeyProblem: false);
    }

    public function render(): JsonResponse
    {
        if ($this->isKeyProblem) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['ai_key' => [$this->getMessage()]],
            ], 422);
        }

        return response()->json(['message' => $this->getMessage()], 503);
    }
}
