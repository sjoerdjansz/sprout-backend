<?php

namespace App\Http\Controllers\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\ListConversationsRequest;
use App\Http\Resources\ConversationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListConversationsController extends Controller
{
    public function __invoke(ListConversationsRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->conversations();

        if ($request->has('topic_id')) {
            $query->where('topic_id', $request->validated('topic_id'));
        }

        $conversations = $query
            ->with('topic', 'previewMessages')
            ->withCount('messages')
            ->latest('updated_at')
            ->latest('id')
            ->get();

        return ConversationResource::collection($conversations);
    }
}
