<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of adding or editing a subtopic: the subtopic as the knowledge map shows it, and the
 * flashcards and questions made from its notes.
 *
 * @property array{subtopic: Subtopic, progress: array<string, mixed>, added_flashcards: int, added_questions: int} $resource
 */
class SavedSubtopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'subtopic' => new KnowledgeMapSubtopicResource([
                'subtopic' => $this->resource['subtopic'],
                'progress' => $this->resource['progress'],
            ]),
            'added' => [
                'flashcards' => $this->resource['added_flashcards'],
                'questions' => $this->resource['added_questions'],
            ],
        ];
    }
}
