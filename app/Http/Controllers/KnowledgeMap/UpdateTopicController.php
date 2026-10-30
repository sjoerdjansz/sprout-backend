<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\UpdateTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\TopicRequest;
use App\Http\Resources\KnowledgeMapTopicResource;

class UpdateTopicController extends Controller
{
    public function __invoke(TopicRequest $request, int $topic, UpdateTopic $updateTopic): KnowledgeMapTopicResource
    {
        // Looked up among the learner's own topics, so someone else's is a 404.
        $topic = $request->user()->topics()->findOrFail($topic);

        return new KnowledgeMapTopicResource($updateTopic->handle(
            topic: $topic,
            title: $request->validated('title'),
            description: $request->validated('description'),
            notes: $request->validated('notes'),
            categoryId: $request->validated('category_id'),
            categoryName: $request->validated('category_name'),
        ));
    }
}
