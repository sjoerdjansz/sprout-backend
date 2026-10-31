<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of saving a conversation: the topic, and how many subtopics this save added. Their flashcards
 * and questions are made in the background, so they aren't counted here.
 *
 * @property array{topic: Topic, added_subtopics: int} $resource
 */
class SavedTopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'topic' => new TopicResource($this->resource['topic']->load('subtopics')),
            'added' => [
                'subtopics' => $this->resource['added_subtopics'],
            ],
        ];
    }
}
