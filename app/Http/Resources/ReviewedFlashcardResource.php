<?php

namespace App\Http\Resources;

use App\Models\Flashcard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A card right after it was rated: its new schedule, and its subtopic with the mastery and facets
 * that rating changed (see ReviewFlashcard).
 *
 * @property array{flashcard: Flashcard, schedule: array<string, mixed>, subtopic_progress: ?array<string, mixed>} $resource
 */
class ReviewedFlashcardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $flashcard = $this->resource['flashcard'];
        $subtopicProgress = $this->resource['subtopic_progress'];

        return [
            'flashcard' => new FlashcardResource(['flashcard' => $flashcard, 'schedule' => $this->resource['schedule']]),
            'subtopic' => $subtopicProgress === null
                ? null
                : new KnowledgeMapSubtopicResource(['subtopic' => $flashcard->subtopic, 'progress' => $subtopicProgress]),
        ];
    }
}
