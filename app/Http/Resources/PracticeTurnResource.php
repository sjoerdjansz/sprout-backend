<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One turn in the open session of a practice session: the learner's message and the tutor's reply.
 *
 * @property array{message: Message, reply: Message} $resource
 */
class PracticeTurnResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'message' => new PracticeMessageResource($this->resource['message']),
            'reply' => new PracticeMessageResource($this->resource['reply']),
        ];
    }
}
