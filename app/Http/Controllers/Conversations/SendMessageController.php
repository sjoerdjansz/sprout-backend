<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\SendMessageToTutor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\JsonResponse;

class SendMessageController extends Controller
{
    public function __invoke(SendMessageRequest $request, int $conversation, SendMessageToTutor $sendMessageToTutor): JsonResponse
    {
        // Looked up among the learner's own conversations, so someone else's is a 404.
        $conversation = $request->user()->conversations()->findOrFail($conversation);

        $sendMessageToTutor->handle($conversation, $request->validated('message'));

        return (new ConversationResource($conversation->load('topic', 'messages')))
            ->response()
            ->setStatusCode(201);
    }
}
