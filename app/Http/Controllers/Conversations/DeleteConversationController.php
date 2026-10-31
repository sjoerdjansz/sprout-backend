<?php

namespace App\Http\Controllers\Conversations;

use App\Actions\DeleteConversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteConversationController extends Controller
{
    public function __invoke(Request $request, int $conversation, DeleteConversation $deleteConversation): Response
    {
        // Looked up among the learner's own conversations, so someone else's is a 404.
        $deleteConversation->handle($request->user()->conversations()->findOrFail($conversation));

        return response()->noContent();
    }
}
