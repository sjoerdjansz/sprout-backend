<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A topic in the knowledge map: its category, goal and subtopics with progress. A topic's own
 * mastery, due count and level are left to the client, which derives them from the subtopics.
 *
 * @property array{topic: Topic, subtopics: array<int, array{subtopic: Subtopic, progress: array<string, mixed>}>} $resource
 */
class KnowledgeMapTopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $topic = $this->resource['topic'];

        return [
            'id' => $topic->id,
            'title' => $topic->title,
            'description' => $topic->description,
            'notes' => $topic->notes,
            'category' => new CategoryResource($topic->category),
            'goal' => [
                'description' => $topic->goal,
                'deadline' => $topic->goal_deadline?->toDateString(),
                'recall_percentage' => $topic->goal_recall_percentage,
            ],
            'subtopics' => KnowledgeMapSubtopicResource::collection($this->resource['subtopics']),
            'created_at' => $topic->created_at,
        ];
    }
}
