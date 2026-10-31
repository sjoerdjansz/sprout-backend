<?php

namespace App\Http\Controllers\Practice;

use App\Actions\SendPracticeMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Practice\SendPracticeMessageRequest;
use App\Http\Resources\PracticeTurnResource;
use Illuminate\Http\JsonResponse;

class SendPracticeMessageController extends Controller
{
    public function __invoke(SendPracticeMessageRequest $request, int $practiceSession, SendPracticeMessage $sendPracticeMessage): JsonResponse
    {
        // Looked up among the learner's own practice sessions, so someone else's is a 404.
        $session = $request->user()->practiceSessions()->findOrFail($practiceSession);

        $result = $sendPracticeMessage->handle($session, $request->user(), $request->validated('message'));

        return (new PracticeTurnResource($result))->response()->setStatusCode(201);
    }
}
