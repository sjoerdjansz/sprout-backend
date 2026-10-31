<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A message in the open session of a practice session: a chat message with the technique the tutor's
 * turn applies, shown as a badge.
 *
 * @mixin Message
 */
class PracticeMessageResource extends JsonResource
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
            'role' => $this->role->value,
            'content' => $this->content,
            'technique' => $this->technique?->value,
            'created_at' => $this->created_at,
        ];
    }
}
