<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListConversationsController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $conversations = $request->user()->conversations()
            ->with('topic')
            ->latest('updated_at')
            ->latest('id')
            ->get();

        return ConversationResource::collection($conversations);
    }
}
