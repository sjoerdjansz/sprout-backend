<?php

namespace App\Actions;

use App\Enums\AnswerVerdict;
use App\Enums\ContentOrigin;
use App\Enums\QuestionType;
use App\Exceptions\AiRequestFailed;
use App\Models\Answer;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves and checks an Explain it explanation of a subtopic. It answers the subtopic's first Explain it
 * question; a subtopic without one gets the question "Explain <subtopic> as if I'm 12." first, so every
 * answer belongs to a question. Only the first version counts for the explain facet, because it shows
 * what the learner knew from memory; the simpler second version gets feedback but no score.
 */
class ExplainSubtopic
{
    public const FIRST_VERSION = 1;

    public const SECOND_VERSION = 2;

    public function __construct(
        private CheckExplanation $checkExplanation,
        private RecordPracticeActivity $recordPracticeActivity,
        private RecalculateSubtopicMastery $recalculateSubtopicMastery,
    ) {}

    /**
     * @return array{question: Question, answer: Answer, verdict: ?AnswerVerdict, subtopic_progress: ?array<string, mixed>}
     *
     * @throws ValidationException when the second version comes before a first one.
     * @throws AiRequestFailed
     */
    public function handle(Subtopic $subtopic, User $learner, string $explanation, int $version, ?StudySession $practiceSession = null): array
    {
        $question = $subtopic->questions()->where('type', QuestionType::Feynman)->orderBy('id')->first();
        $prompt = $question?->prompt ?? self::defaultPrompt($subtopic);
        $isFirstVersion = $version === self::FIRST_VERSION;
        $firstVersion = $isFirstVersion ? null : $this->latestFirstVersion($question, $learner);

        // Checked before anything is stored, so a failed AI call leaves no question or answer behind.
        $check = $this->checkExplanation->handle(
            learner: $learner,
            subtopic: $subtopic->load('flashcards'),
            prompt: $prompt,
            keyPoints: $question?->model_answer,
            explanation: $explanation,
            firstVersion: $firstVersion,
        );

        return DB::transaction(function () use ($subtopic, $learner, $explanation, $question, $prompt, $isFirstVersion, $check, $practiceSession): array {
            $question ??= $subtopic->questions()->create([
                'topic_id' => $subtopic->topic_id,
                'type' => QuestionType::Feynman,
                'prompt' => $prompt,
                'model_answer' => null,
                'origin' => ContentOrigin::Ai,
            ]);

            $answer = $question->answers()->create([
                'user_id' => $learner->id,
                'study_session_id' => $practiceSession?->id,
                'response' => $explanation,
                // A second version without a score stays out of the facet, like any ungraded answer.
                'score' => $isFirstVersion ? $check['score'] : null,
                'is_correct' => $isFirstVersion ? $check['score'] >= SummariseSubtopicProgress::HOLDING_SCORE : null,
                'feedback' => $check['feedback'],
                'gaps' => $check['gaps'],
                'answered_at' => now(),
            ]);

            $subtopicProgress = null;

            if ($isFirstVersion) {
                $this->recordPracticeActivity->handle($learner, explanationsChecked: 1);
                $subtopicProgress = $this->recalculateSubtopicMastery->handle($subtopic, $learner);
            }

            return [
                'question' => $question,
                'answer' => $answer,
                'verdict' => $isFirstVersion ? AnswerVerdict::of($answer) : null,
                'subtopic_progress' => $subtopicProgress,
            ];
        });
    }

    /**
     * The prompt for a subtopic that has no Explain it question yet; the practice session shows the same.
     */
    public static function defaultPrompt(Subtopic $subtopic): string
    {
        return "Explain {$subtopic->title} as if I'm 12.";
    }

    /**
     * The checked first version the second one simplifies: the learner's latest scored explanation.
     *
     * @throws ValidationException
     */
    private function latestFirstVersion(?Question $question, User $learner): Answer
    {
        $firstVersion = $question?->answers()
            ->where('user_id', $learner->id)
            ->whereNotNull('score')
            ->orderByDesc('answered_at')
            ->orderByDesc('id')
            ->first();

        if ($firstVersion === null) {
            throw ValidationException::withMessages(['version' => 'Write your first explanation before a simpler one.']);
        }

        return $firstVersion;
    }
}
