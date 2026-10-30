<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subtopic for the subtopic page: the knowledge map's subtopic, plus its topic for the header and
 * the counts for the tabs.
 *
 * @property array{subtopic: Subtopic, progress: array<string, mixed>, counts: array{flashcards: int, questions: int, sessions: int}} $resource
 */
class SubtopicDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subtopic = $this->resource['subtopic'];

        return [
            ...(new KnowledgeMapSubtopicResource([
                'subtopic' => $subtopic,
                'progress' => $this->resource['progress'],
            ]))->toArray($request),
            'topic' => [
                'id' => $subtopic->topic->id,
                'title' => $subtopic->topic->title,
                'category' => new CategoryResource($subtopic->topic->category),
            ],
            'counts' => $this->resource['counts'],
        ];
    }
}
