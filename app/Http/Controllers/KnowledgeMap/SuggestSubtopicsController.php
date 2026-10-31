<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\SuggestSubtopics;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubtopicSuggestionsResource;
use Illuminate\Http\Request;

class SuggestSubtopicsController extends Controller
{
    public function __invoke(Request $request, int $topic, SuggestSubtopics $suggestSubtopics): SubtopicSuggestionsResource
    {
        // Looked up among the learner's own topics, so someone else's is a 404.
        $topic = $request->user()->topics()->findOrFail($topic);

        return new SubtopicSuggestionsResource($suggestSubtopics->handle($topic));
    }
}
