<?php

namespace App\Http\Resources;

use App\Models\StudySession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat with the tutor. The chat history lists these without `messages`.
 *
 * @mixin StudySession
 */
class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->topic?->title ?? $this->draft['title'] ?? null,
            'topic' => $this->topic === null ? null : [
                'id' => $this->topic->id,
                'title' => $this->topic->title,
            ],
            'is_saved' => $this->isSaved(),
            'draft' => $this->draft,
            'started_at' => $this->started_at,
            'last_activity_at' => $this->updated_at,
            'messages' => MessageResource::collection($this->whenLoaded('messages', fn () => $this->messages->sortBy('id')->values())),
        ];
    }
}
