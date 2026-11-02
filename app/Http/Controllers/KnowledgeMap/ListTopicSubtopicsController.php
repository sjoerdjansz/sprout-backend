<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\SummariseTopic;
use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeMapTopicResource;
use Illuminate\Http\Request;

class ListTopicSubtopicsController extends Controller
{
    public function __invoke(Request $request, int $topic, SummariseTopic $summariseTopic): KnowledgeMapTopicResource
    {
        // Looked up among the learner's own topics, so someone else's is a 404.
        $topic = $request->user()->topics()->findOrFail($topic);

        return new KnowledgeMapTopicResource($summariseTopic->handle($topic, $request->user()));
    }
}
