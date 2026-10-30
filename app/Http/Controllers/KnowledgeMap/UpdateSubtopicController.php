<?php

namespace App\Http\Controllers\KnowledgeMap;

use App\Actions\UpdateSubtopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\KnowledgeMap\SubtopicRequest;
use App\Http\Resources\SavedSubtopicResource;

class UpdateSubtopicController extends Controller
{
    public function __invoke(SubtopicRequest $request, int $subtopic, UpdateSubtopic $updateSubtopic): SavedSubtopicResource
    {
        // Looked up among the learner's own subtopics, so someone else's is a 404.
        $subtopic = $request->user()->subtopics()->findOrFail($subtopic);

        return new SavedSubtopicResource($updateSubtopic->handle(
            subtopic: $subtopic,
            targetTopic: $request->user()->topics()->findOrFail($request->validated('topic_id')),
            title: $request->validated('title'),
            description: $request->validated('description'),
            notes: $request->validated('notes'),
        ));
    }
}
