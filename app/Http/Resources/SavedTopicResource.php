<?php

namespace App\Http\Resources;

use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of saving a conversation: the topic, and what this save added to it.
 *
 * @property array{topic: Topic, added_subtopics: int, added_flashcards: int, added_questions: int} $resource
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
                'flashcards' => $this->resource['added_flashcards'],
                'questions' => $this->resource['added_questions'],
            ],
        ];
    }
}
