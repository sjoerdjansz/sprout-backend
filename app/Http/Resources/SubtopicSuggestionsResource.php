<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The subtopics SuggestSubtopics found in a topic's source, for the learner to review. Nothing is saved yet.
 *
 * @property array<int, array{title: string, description: ?string, notes: ?string}> $resource
 */
class SubtopicSuggestionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'suggestions' => array_map(fn (array $suggestion): array => [
                'title' => $suggestion['title'],
                'description' => $suggestion['description'],
                'notes' => $suggestion['notes'],
            ], $this->resource),
        ];
    }
}
