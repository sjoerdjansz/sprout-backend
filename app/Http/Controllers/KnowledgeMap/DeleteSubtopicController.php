<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\DeleteSubtopic;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteSubtopicController extends Controller
{
    public function __invoke(Request $request, int $subtopic, DeleteSubtopic $deleteSubtopic): Response
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $deleteSubtopic->handle($request->user()->subtopics()->findOrFail($subtopic));

        return response()->noContent();
    }
}
