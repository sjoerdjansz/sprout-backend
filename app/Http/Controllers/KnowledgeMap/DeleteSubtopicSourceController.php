<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\DeleteSubtopicSource;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeleteSubtopicSourceController extends Controller
{
    public function __invoke(Request $request, int $subtopic, int $source, DeleteSubtopicSource $deleteSubtopicSource): Response
    {
        // Looked up among the learner's own subtopics and then that subtopic's sources, so anything else is a 404.
        $source = $request->user()->subtopics()->findOrFail($subtopic)->sources()->findOrFail($source);

        $deleteSubtopicSource->handle($source);

        return response()->noContent();
    }
}
