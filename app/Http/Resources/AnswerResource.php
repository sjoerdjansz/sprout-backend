<?php

namespace App\Http\Resources;

use App\Enums\AnswerVerdict;
use App\Models\Answer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A learner's answer to a question with how it went: the `latest_answer` of a question, and the
 * answer that was just checked in practice. The verdict is null only for an Explain it second version,
 * which gets feedback but no score.
 *
 * @property array{answer: Answer, verdict: ?AnswerVerdict} $resource
 */
class AnswerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $answer = $this->resource['answer'];

        return [
            'response' => $answer->response,
            'option_id' => $answer->question_option_id,
            'score' => $answer->score,
            'verdict' => $this->resource['verdict']?->value,
            'feedback' => $answer->feedback,
            'gaps' => $answer->gaps,
            'answered_at' => $answer->answered_at,
        ];
    }
}
