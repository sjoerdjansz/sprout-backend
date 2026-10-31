<?php

namespace App\Http\Resources;

use App\Enums\ReviewRating;
use App\Models\Flashcard;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A flashcard on the subtopic page, with its schedule (see SummariseFlashcard).
 *
 * @property array{flashcard: Flashcard, schedule: array{paused: bool, is_due: bool, due_on: string, last_rating: ?ReviewRating, last_reviewed_at: ?CarbonInterface, recall: ?int, next_review_in_minutes: array{again: int, hard: int, good: int, easy: int}}} $resource
 */
class FlashcardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $flashcard = $this->resource['flashcard'];
        $schedule = $this->resource['schedule'];

        return [
            'id' => $flashcard->id,
            'topic_id' => $flashcard->topic_id,
            'subtopic_id' => $flashcard->subtopic_id,
            'front' => $flashcard->front,
            'back' => $flashcard->back,
            'origin' => $flashcard->origin->value,
            'paused' => $schedule['paused'],
            'is_due' => $schedule['is_due'],
            'due_on' => $schedule['due_on'],
            'last_rating' => $schedule['last_rating']?->value,
            'last_reviewed_at' => $schedule['last_reviewed_at'],
            'recall' => $schedule['recall'],
            'next_review_in_minutes' => $schedule['next_review_in_minutes'],
            'created_at' => $flashcard->created_at,
        ];
    }
}
