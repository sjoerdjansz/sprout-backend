<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of adding several subtopics to a topic: the new subtopics as the knowledge map shows them,
 * and how many there are. Their flashcards and questions are made in the background.
 *
 * @property array<int, array{subtopic: Subtopic, progress: array<string, mixed>}> $resource
 */
class AddedSubtopicsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'subtopics' => KnowledgeMapSubtopicResource::collection($this->resource),
            'added' => [
                'subtopics' => count($this->resource),
            ],
        ];
    }
}
