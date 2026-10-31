<?php

namespace App\Actions;

use App\Enums\PracticeMode;
use App\Enums\QuestionType;
use App\Enums\SessionKind;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts a practice session on a topic, one of its subtopics or everything due today across topics (no
 * topic: a "due review", flashcards only), with the plan the learner picked: the
 * modes and the cards and questions the frontend put in its blocks, so a reload can resume it. A session
 * that was still open is finished first: there is one session at a time. The mastery of the scope's
 * subtopics is kept for "before and after".
 */
class StartPracticeSession
{
    public function __construct(
        private FinishPracticeSession $finishPracticeSession,
        private SnapshotMastery $snapshotMastery,
        private SummarisePracticeSession $summarisePracticeSession,
    ) {}

    /**
     * @param  ?Topic  $topic  null for a review of everything due today, across topics
     * @param  array<int, PracticeMode>  $modes
     * @param  array<int, int>  $cardIds  in the order the flashcard block shows them
     * @param  array<int, int>  $questionIds  in the order the quiz shows them
     * @return array<string, mixed> the session, see SummarisePracticeSession
     *
     * @throws ValidationException when the plan holds something outside the scope.
     */
    public function handle(User $learner, ?Topic $topic, ?Subtopic $subtopic, array $modes, array $cardIds, array $questionIds, ?Subtopic $explainSubtopic): array
    {
        if ($topic === null) {
            $this->ensurePlanFitsDueReview($learner, $modes, $cardIds, $questionIds, $explainSubtopic);
        } else {
            $this->ensurePlanFitsScope($topic, $subtopic, $cardIds, $questionIds, $explainSubtopic);
        }

        $session = DB::transaction(function () use ($learner, $topic, $subtopic, $modes, $cardIds, $questionIds, $explainSubtopic) {
            $learner->practiceSessions()->whereNull('ended_at')->get()
                ->each(fn ($openSession) => $this->finishPracticeSession->handle($openSession, $learner));

            $session = $learner->practiceSessions()->create([
                'kind' => SessionKind::Review,
                'topic_id' => $topic?->id,
                'subtopic_id' => $subtopic?->id,
                'plan' => [
                    'modes' => array_map(fn (PracticeMode $mode): string => $mode->value, $modes),
                    'card_ids' => $cardIds,
                    'question_ids' => $questionIds,
                    'explain_subtopic_id' => $explainSubtopic?->id,
                ],
                'started_at' => now(),
            ]);
            $session->update(['mastery_before' => $this->snapshotMastery->handle($session, $learner)]);

            return $session;
        });

        return $this->summarisePracticeSession->handle($session, $learner);
    }

    /**
     * Only flashcards have a schedule, so only they can be due; Explain it and the open session need one
     * subject, which a mix of topics doesn't have. The cards may come from any of the learner's topics.
     *
     * @param  array<int, PracticeMode>  $modes
     * @param  array<int, int>  $cardIds
     * @param  array<int, int>  $questionIds
     *
     * @throws ValidationException
     */
    private function ensurePlanFitsDueReview(User $learner, array $modes, array $cardIds, array $questionIds, ?Subtopic $explainSubtopic): void
    {
        $errors = [];

        if ($modes !== [PracticeMode::Cards]) {
            $errors['modes'] = 'A review of everything due is flashcards only.';
        }

        if ($cardIds === []) {
            $errors['card_ids'] = 'Nothing is due today, so there is nothing to review.';
        } elseif ($learner->flashcards()->whereIn('id', $cardIds)->count() !== count($cardIds)) {
            $errors['card_ids'] = 'Pick cards from what you\'re practising.';
        }

        if ($questionIds !== []) {
            $errors['question_ids'] = 'A review of everything due has no quiz questions.';
        }

        if ($explainSubtopic !== null) {
            $errors['explain_subtopic_id'] = 'A review of everything due has nothing to explain.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, int>  $cardIds
     * @param  array<int, int>  $questionIds
     *
     * @throws ValidationException
     */
    private function ensurePlanFitsScope(Topic $topic, ?Subtopic $subtopic, array $cardIds, array $questionIds, ?Subtopic $explainSubtopic): void
    {
        $errors = [];

        if ($subtopic !== null && $subtopic->topic_id !== $topic->id) {
            $errors['subtopic_id'] = 'Pick a subtopic of this topic.';
        }

        $cardsInScope = $topic->flashcards()
            ->when($subtopic !== null, fn ($query) => $query->where('subtopic_id', $subtopic->id))
            ->whereIn('id', $cardIds)
            ->count();

        if ($cardsInScope !== count($cardIds)) {
            $errors['card_ids'] = 'Pick cards from what you\'re practising.';
        }

        // The quiz has multiple choice and open questions; Explain it questions belong to their own block.
        $questionsInScope = $topic->questions()
            ->when($subtopic !== null, fn ($query) => $query->where('subtopic_id', $subtopic->id))
            ->whereIn('type', [QuestionType::MultipleChoice, QuestionType::Open])
            ->whereIn('id', $questionIds)
            ->count();

        if ($questionsInScope !== count($questionIds)) {
            $errors['question_ids'] = 'Pick quiz questions from what you\'re practising.';
        }

        $explainSubtopicFits = $explainSubtopic === null
            || ($explainSubtopic->topic_id === $topic->id && ($subtopic === null || $explainSubtopic->id === $subtopic->id));

        if (! $explainSubtopicFits) {
            $errors['explain_subtopic_id'] = 'Pick a subtopic from what you\'re practising to explain.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
