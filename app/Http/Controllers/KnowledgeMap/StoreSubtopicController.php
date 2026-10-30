<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\CreateSubtopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\SubtopicRequest;
use App\Http\Resources\SavedSubtopicResource;
use Illuminate\Http\JsonResponse;

class StoreSubtopicController extends Controller
{
    public function __invoke(SubtopicRequest $request, CreateSubtopic $createSubtopic): JsonResponse
    {
        $result = $createSubtopic->handle(
            topic: $request->user()->topics()->findOrFail($request->validated('topic_id')),
            title: $request->validated('title'),
            description: $request->validated('description'),
            notes: $request->validated('notes'),
        );

        return (new SavedSubtopicResource($result))->response()->setStatusCode(201);
    }
}
