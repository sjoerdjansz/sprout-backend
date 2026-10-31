<?php

namespace App\Http\Resources;

use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of adding or editing a subtopic: the subtopic as the knowledge map shows it. Flashcards and
 * questions from its notes are made in the background; its generation_status says when they're ready.
 *
 * @property array{subtopic: Subtopic, progress: array<string, mixed>} $resource
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
        ];
    }
}
