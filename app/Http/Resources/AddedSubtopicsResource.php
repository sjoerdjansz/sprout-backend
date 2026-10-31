<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of adding several subtopics to a topic: the new subtopics as the knowledge map shows them,
 * and what was made for them.
 *
 * @property array{subtopics: array<int, array{subtopic: Subtopic, progress: array<string, mixed>}>, added_flashcards: int, added_questions: int} $resource
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
            'subtopics' => KnowledgeMapSubtopicResource::collection($this->resource['subtopics']),
            'added' => [
                'subtopics' => count($this->resource['subtopics']),
                'flashcards' => $this->resource['added_flashcards'],
                'questions' => $this->resource['added_questions'],
            ],
        ];
    }
}
