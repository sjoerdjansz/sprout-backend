<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\ExplainSubtopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\ExplainSubtopicRequest;
use App\Http\Resources\ExplanationResource;
use Illuminate\Http\JsonResponse;

class ExplainSubtopicController extends Controller
{
    public function __invoke(ExplainSubtopicRequest $request, int $subtopic, ExplainSubtopic $explainSubtopic): JsonResponse
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        $result = $explainSubtopic->handle(
            subtopic: $subtopic,
            learner: $request->user(),
            explanation: $request->validated('explanation'),
            version: (int) $request->validated('version'),
            practiceSession: $request->practiceSession(),
        );

        return (new ExplanationResource($result))->response()->setStatusCode(201);
    }
}
