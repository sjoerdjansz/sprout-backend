<?php

namespace App\Http\Resources;

use App\Models\StudySession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chat with the tutor. The chat history lists these without `messages`, but with the number of
 * messages and a preview of the first few.
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
            // Drafts from before categories existed have no category yet.
            'draft' => $this->draft === null ? null : [...$this->draft, 'category' => $this->draft['category'] ?? null],
            'started_at' => $this->started_at,
            'last_activity_at' => $this->updated_at,
            'message_count' => $this->whenCounted('messages'),
            'preview' => MessageResource::collection($this->whenLoaded('previewMessages')),
            'messages' => MessageResource::collection($this->whenLoaded('messages', fn () => $this->messages->sortBy('id')->values())),
        ];
    }
}
