<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\CreateTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\TopicRequest;
use App\Http\Resources\KnowledgeMapTopicResource;
use Illuminate\Http\JsonResponse;

class StoreTopicController extends Controller
{
    public function __invoke(TopicRequest $request, CreateTopic $createTopic): JsonResponse
    {
        $topic = $createTopic->handle(
            learner: $request->user(),
            title: $request->validated('title'),
            description: $request->validated('description'),
            notes: $request->validated('notes'),
            categoryId: $request->validated('category_id'),
            categoryName: $request->validated('category_name'),
        );

        return (new KnowledgeMapTopicResource($topic))->response()->setStatusCode(201);
    }
}
