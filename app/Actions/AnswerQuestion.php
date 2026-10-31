<?php

namespace App\Actions;

use App\Enums\AnswerVerdict;
use App\Enums\QuestionType;
use App\Exceptions\AiRequestFailed;
use App\Models\Answer;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves and grades a learner's answer in the retrieval quiz. Multiple choice is checked here, open
 * answers by the AI. Only the outcome of a question counts (specs/practice-session.md): a wrong first
 * pick is kept without a score, so it doesn't count, and right on the second try scores like a card
 * rated hard. A graded answer counts towards today's activity and updates the subtopic's mastery.
 */
class AnswerQuestion
{
    /** Right on the second try: remembered, but not without help, like a card rated hard. */
    public const SECOND_TRY_SCORE = 60;

    public function __construct(
        private CheckOpenAnswer $checkOpenAnswer,
        private RecordPracticeActivity $recordPracticeActivity,
        private RecalculateSubtopicMastery $recalculateSubtopicMastery,
    ) {}

    /**
     * @param  bool  $doesNotKnow  "I don't know": the learner gives up on the question
     * @return array{question: Question, answer: Answer, verdict: AnswerVerdict, try_again: bool, subtopic_progress: ?array<string, mixed>}
     *
     * @throws ValidationException when the answer doesn't fit the question.
     * @throws AiRequestFailed
     */
    public function handle(Question $question, User $learner, ?int $optionId, ?string $response, bool $isSecondTry, bool $doesNotKnow, ?StudySession $practiceSession = null): array
    {
        $grade = match ($question->type) {
            QuestionType::MultipleChoice => $this->gradeChoice($question, $optionId, $isSecondTry, $doesNotKnow),
            QuestionType::Open => $this->gradeOpenAnswer($question, $learner, $response, $doesNotKnow),
            QuestionType::Feynman => throw ValidationException::withMessages([
                'question' => 'Answer Explain it questions in the Explain it block.',
            ]),
        };

        // Only an answer with a score is the outcome of the question; a wrong first pick gets another try.
        $isGraded = $grade['score'] !== null;

        return DB::transaction(function () use ($question, $learner, $grade, $isGraded, $practiceSession): array {
            $answer = $question->answers()->create([
                'user_id' => $learner->id,
                'study_session_id' => $practiceSession?->id,
                'question_option_id' => $grade['option_id'],
                'response' => $grade['response'],
                'score' => $grade['score'],
                'is_correct' => $grade['is_correct'],
                'feedback' => $grade['feedback'],
                'answered_at' => now(),
            ]);

            $subtopicProgress = null;

            if ($isGraded) {
                $this->recordPracticeActivity->handle($learner, questionsAnswered: 1);

                if ($question->subtopic !== null) {
                    $subtopicProgress = $this->recalculateSubtopicMastery->handle($question->subtopic, $learner);
                }
            }

            return [
                'question' => $question,
                'answer' => $answer,
                'verdict' => $isGraded ? AnswerVerdict::of($answer) : AnswerVerdict::NotQuite,
                'try_again' => ! $isGraded,
                'subtopic_progress' => $subtopicProgress,
            ];
        });
    }

    /**
     * @return array{option_id: ?int, response: null, score: ?int, is_correct: bool, feedback: ?string}
     *
     * @throws ValidationException
     */
    private function gradeChoice(Question $question, ?int $optionId, bool $isSecondTry, bool $doesNotKnow): array
    {
        if ($doesNotKnow) {
            return ['option_id' => null, 'response' => null, 'score' => 0, 'is_correct' => false, 'feedback' => null];
        }

        $option = $optionId === null ? null : $question->options()->find($optionId);

        if ($option === null) {
            throw ValidationException::withMessages([
                'option_id' => $optionId === null ? 'Pick an answer first.' : 'Pick one of this question\'s options.',
            ]);
        }

        return [
            'option_id' => $option->id,
            'response' => null,
            'score' => $this->choiceScore($option, $isSecondTry),
            // Correct means right straight away: the second try is "almost", as its score says.
            'is_correct' => $option->is_correct && ! $isSecondTry,
            'feedback' => $option->explanation,
        ];
    }

    /**
     * Null for a wrong first pick: that is not the outcome yet.
     */
    private function choiceScore(QuestionOption $option, bool $isSecondTry): ?int
    {
        if ($option->is_correct) {
            return $isSecondTry ? self::SECOND_TRY_SCORE : 100;
        }

        return $isSecondTry ? 0 : null;
    }

    /**
     * @return array{option_id: null, response: ?string, score: int, is_correct: bool, feedback: ?string}
     *
     * @throws ValidationException
     * @throws AiRequestFailed
     */
    private function gradeOpenAnswer(Question $question, User $learner, ?string $response, bool $doesNotKnow): array
    {
        // Nothing to check, so no AI call.
        if ($doesNotKnow) {
            return ['option_id' => null, 'response' => null, 'score' => 0, 'is_correct' => false, 'feedback' => null];
        }

        if ($response === null || trim($response) === '') {
            throw ValidationException::withMessages(['response' => 'Write your answer first.']);
        }

        return [
            'option_id' => null,
            'response' => $response,
            ...$this->checkOpenAnswer->handle($learner, $question, $response),
        ];
    }
}
