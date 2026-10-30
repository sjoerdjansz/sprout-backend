<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\StartConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;

class StartConversationController extends Controller
{
    public function __invoke(StartConversationRequest $request, StartConversation $startConversation): JsonResponse
    {
        $topicId = $request->validated('topic_id');

        $conversation = $startConversation->handle(
            learner: $request->user(),
            firstMessage: $request->validated('message'),
            topic: $topicId === null ? null : Topic::findOrFail($topicId),
        );

        return (new ConversationResource($conversation->load('topic', 'messages')))
            ->response()
            ->setStatusCode(201);
    }
}
