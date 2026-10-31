<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\AddSubtopics;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\AddSubtopicsRequest;
use App\Http\Resources\AddedSubtopicsResource;
use Illuminate\Http\JsonResponse;

class AddSubtopicsController extends Controller
{
    public function __invoke(AddSubtopicsRequest $request, int $topic, AddSubtopics $addSubtopics): JsonResponse
    {
        // Looked up among the learner's own topics, so someone else's is a 404.
        $topic = $request->user()->topics()->findOrFail($topic);

        $result = $addSubtopics->handle($topic, $request->subtopics());

        return (new AddedSubtopicsResource($result))->response()->setStatusCode(201);
    }
}
