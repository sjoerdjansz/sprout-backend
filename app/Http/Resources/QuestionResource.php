<?php

namespace App\Http\Resources;

use App\Enums\AnswerVerdict;
use App\Models\Answer;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question on the subtopic page, with the learner's latest graded answer (see ListSubtopicQuestions).
 *
 * @property array{question: Question, latest_answer: ?Answer, verdict: ?AnswerVerdict} $resource
 */
class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->resource['question'];
        $answer = $this->resource['latest_answer'];

        return [
            'id' => $question->id,
            'subtopic_id' => $question->subtopic_id,
            'type' => $question->type->value,
            'prompt' => $question->prompt,
            'origin' => $question->origin->value,
            'options' => $question->options->map(fn (QuestionOption $option): array => [
                'id' => $option->id,
                'label' => $option->label,
                'is_correct' => $option->is_correct,
                'explanation' => $option->explanation,
            ])->values(),
            'latest_answer' => $answer === null ? null : [
                'response' => $answer->response,
                'option_id' => $answer->question_option_id,
                'score' => $answer->score,
                'verdict' => $this->resource['verdict']->value,
                'feedback' => $answer->feedback,
                'answered_at' => $answer->answered_at,
            ],
            'created_at' => $question->created_at,
        ];
    }
}
