<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\DeleteTopic;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteTopicController extends Controller
{
    public function __invoke(Request $request, int $topic, DeleteTopic $deleteTopic): Response
    {
        // Looked up among the learner's own topics, so someone else's is a 404.
        $deleteTopic->handle($request->user()->topics()->findOrFail($topic));

        return response()->noContent();
    }
}
