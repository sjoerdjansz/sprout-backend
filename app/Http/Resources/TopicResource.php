<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Topic
 */
class TopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category' => new CategoryResource($this->category),
            'goal' => [
                'description' => $this->goal,
                'deadline' => $this->goal_deadline?->toDateString(),
                'recall_percentage' => $this->goal_recall_percentage,
            ],
            'subtopics' => $this->whenLoaded('subtopics', fn (): array => $this->subtopics->sortBy('position')->map(fn ($subtopic): array => [
                'id' => $subtopic->id,
                'title' => $subtopic->title,
            ])->values()->all()),
            'created_at' => $this->created_at,
        ];
    }
}
