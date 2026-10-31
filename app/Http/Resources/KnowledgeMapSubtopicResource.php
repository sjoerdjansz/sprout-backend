<?php

namespace App\Http\Resources;

use App\Enums\SourceType;
use App\Models\Subtopic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subtopic in the knowledge map, with the learner's progress on it (see SummariseSubtopicProgress).
 *
 * @property array{subtopic: Subtopic, progress: array<string, mixed>} $resource
 */
class KnowledgeMapSubtopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subtopic = $this->resource['subtopic'];
        $progress = $this->resource['progress'];

        return [
            'id' => $subtopic->id,
            'topic_id' => $subtopic->topic_id,
            'title' => $subtopic->title,
            'description' => $subtopic->description,
            // Only the learner's own notes: the tutor's notes and file parts are sources of their own.
            'notes' => $subtopic->sourceContent(SourceType::Own),
            'generation_status' => $subtopic->generation_status,
            'mastery' => $progress['mastery'],
            'due_cards' => $progress['due_cards'],
            'next_review_on' => $progress['next_review_on'],
            'is_new' => $progress['is_new'],
            'slipping' => $progress['slipping'],
            'facets' => $progress['facets'],
        ];
    }
}
