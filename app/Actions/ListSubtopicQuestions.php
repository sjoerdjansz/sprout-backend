<?php

namespace App\Actions;

use App\Enums\AnswerVerdict;
use App\Models\Answer;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Every question of a subtopic, oldest first, answered or not, with the learner's latest graded
 * answer and how it went. Answers that haven't been graded yet are skipped, as in the facets: they
 * have no verdict or feedback to show.
 */
class ListSubtopicQuestions
{
    /**
     * @return array<int, array{question: Question, latest_answer: ?Answer, verdict: ?AnswerVerdict}>
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        return $subtopic->questions()
            ->with([
                'options' => fn (Relation $query) => $query->orderBy('position'),
                'answers' => fn (Relation $query) => $query->where('user_id', $learner->id)->whereNotNull('score'),
            ])
            ->orderBy('id')
            ->get()
            ->map(function (Question $question): array {
                $latestAnswer = $question->answers->sortByDesc(fn (Answer $answer): array => [$answer->answered_at->getTimestamp(), $answer->id])->first();

                return [
                    'question' => $question,
                    'latest_answer' => $latestAnswer,
                    'verdict' => $latestAnswer === null ? null : AnswerVerdict::of($latestAnswer),
                ];
            })
            ->all();
    }
}
