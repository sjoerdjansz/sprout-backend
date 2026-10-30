<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\SaveConversationToKnowledgeMap;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\SaveConversationRequest;
use App\Http\Resources\SavedTopicResource;
use Illuminate\Http\JsonResponse;

class SaveConversationController extends Controller
{
    public function __invoke(SaveConversationRequest $request, int $conversation, SaveConversationToKnowledgeMap $saveConversation): JsonResponse
    {
        // Looked up among the learner's own conversations, so someone else's is a 404.
        $conversation = $request->user()->conversations()->findOrFail($conversation);

        $result = $saveConversation->handle(
            conversation: $conversation,
            title: $request->validated('title'),
            goal: $request->validated('goal.description'),
            goalDeadline: $request->validated('goal.deadline'),
            goalRecallPercentage: $request->integer('goal.recall_percentage'),
            subtopics: $request->subtopics(),
        );

        return (new SavedTopicResource($result))->response()->setStatusCode(201);
    }
}
