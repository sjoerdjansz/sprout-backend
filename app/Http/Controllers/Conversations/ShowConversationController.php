<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\Request;

class ShowConversationController extends Controller
{
    public function __invoke(Request $request, int $conversation): ConversationResource
    {
        // Looked up among the learner's own conversations, so someone else's is a 404.
        $conversation = $request->user()->conversations()->with('topic', 'messages')->findOrFail($conversation);

        return new ConversationResource($conversation);
    }
}
