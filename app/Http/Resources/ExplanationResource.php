<?php

namespace App\Http\Resources;

use App\Enums\AnswerVerdict;
use App\Models\Answer;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An Explain it explanation right after it was checked: the question it answers, the answer with its
 * gaps and feedback, and the subtopic with the mastery and facets a first version changed (see
 * ExplainSubtopic).
 *
 * @property array{question: Question, answer: Answer, verdict: ?AnswerVerdict, subtopic_progress: ?array<string, mixed>} $resource
 */
class ExplanationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $question = $this->resource['question'];
        $subtopicProgress = $this->resource['subtopic_progress'];

        return [
            'question' => [
                'id' => $question->id,
                'prompt' => $question->prompt,
            ],
            'answer' => new AnswerResource(['answer' => $this->resource['answer'], 'verdict' => $this->resource['verdict']]),
            'subtopic' => $subtopicProgress === null
                ? null
                : new KnowledgeMapSubtopicResource(['subtopic' => $question->subtopic, 'progress' => $subtopicProgress]),
        ];
    }
}
