<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\ShowSubtopic;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubtopicDetailResource;
use Illuminate\Http\Request;

class ShowSubtopicController extends Controller
{
    public function __invoke(Request $request, int $subtopic, ShowSubtopic $showSubtopic): SubtopicDetailResource
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        return new SubtopicDetailResource($showSubtopic->handle($subtopic, $request->user()));
    }
}
