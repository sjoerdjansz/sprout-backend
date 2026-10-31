<?php

namespace App\Http\Resources;

use App\Enums\AnswerVerdict;
use App\Models\Answer;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An answer right after it was checked: how it went, whether the learner gets another try, and the
 * subtopic with the mastery and facets a graded answer changed (see AnswerQuestion).
 *
 * @property array{question: Question, answer: Answer, verdict: AnswerVerdict, try_again: bool, subtopic_progress: ?array<string, mixed>} $resource
 */
class AnsweredQuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subtopicProgress = $this->resource['subtopic_progress'];

        return [
            'answer' => new AnswerResource(['answer' => $this->resource['answer'], 'verdict' => $this->resource['verdict']]),
            'try_again' => $this->resource['try_again'],
            'subtopic' => $subtopicProgress === null
                ? null
                : new KnowledgeMapSubtopicResource(['subtopic' => $this->resource['question']->subtopic, 'progress' => $subtopicProgress]),
        ];
    }
}
