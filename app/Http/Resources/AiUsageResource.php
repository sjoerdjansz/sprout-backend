<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * This month's AI calls, as SummariseAiUsage counts them.
 *
 * @property array{month: string, tutor_replies: int, answers_checked: int, explain_feedback: int, card_batches: int, subtopic_suggestions: int, total: int} $resource
 */
class AiUsageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource['month'],
            'tutor_replies' => $this->resource['tutor_replies'],
            'answers_checked' => $this->resource['answers_checked'],
            'explain_feedback' => $this->resource['explain_feedback'],
            'card_batches' => $this->resource['card_batches'],
            'subtopic_suggestions' => $this->resource['subtopic_suggestions'],
            'total' => $this->resource['total'],
        ];
    }
}
