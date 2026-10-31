<?php

namespace App\Actions;

use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use App\Models\Question;
use App\Models\Subtopic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * How well a learner knows a subtopic, worked out from the raw reviews and answers (the rules are in
 * specs/knowledge-map.md). Expects the subtopic's flashcards with reviews, questions with the learner's
 * answers, and the learner's progress row to be loaded; it runs no queries of its own.
 */
class SummariseSubtopicProgress
{
    /** A facet holds once it scores at least this on two spaced reviews in a row. */
    public const HOLDING_SCORE = 85;

    /** Slipping: the subtopic once reached this, and its expected recall is now below it. */
    public const SLIPPING_THRESHOLD = 65;

    /**
     * @return array{mastery: int, due_cards: int, next_review_on: ?string, is_new: bool, slipping: bool, facets: array{cards: array{percent: ?int, holding: bool}, explain: array{percent: ?int, holding: bool}, quiz: array{percent: ?int, holding: bool}}}
     */
    public function handle(Subtopic $subtopic, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $activeCards = $subtopic->flashcards->whereNull('suspended_at');
        $progress = $subtopic->progress->first();

        $cardScores = $subtopic->flashcards
            ->flatMap(fn (Flashcard $flashcard): Collection => $flashcard->reviews)
            ->map(fn (FlashcardReview $review): array => ['at' => $review->reviewed_at, 'score' => $review->rating->score()]);
        $quizScores = $this->answerScores($subtopic, [QuestionType::MultipleChoice, QuestionType::Open]);
        $explainScores = $this->answerScores($subtopic, [QuestionType::Feynman]);

        $isNew = $cardScores->isEmpty() && $quizScores->isEmpty() && $explainScores->isEmpty();
        $peakMastery = $progress?->peak_mastery ?? 0;
        $expectedRecall = $this->expectedRecall($activeCards, $now);

        return [
            'mastery' => $progress?->mastery ?? 0,
            'due_cards' => $activeCards->filter(fn (Flashcard $flashcard): bool => $flashcard->due_at->lte($now->endOfDay()))->count(),
            'next_review_on' => $isNew ? null : $this->nextReviewOn($activeCards, $now),
            'is_new' => $isNew,
            'slipping' => $peakMastery >= self::SLIPPING_THRESHOLD
                && $expectedRecall !== null
                && $expectedRecall * 100 < self::SLIPPING_THRESHOLD,
            'facets' => [
                'cards' => $this->facet($cardScores, $timezone),
                'explain' => $this->facet($explainScores, $timezone),
                'quiz' => $this->facet($quizScores, $timezone),
            ],
        ];
    }

    /**
     * What handle() needs loaded on a subtopic: only this learner's answers and progress.
     *
     * @return array<string|int, mixed>
     */
    public static function relations(User $learner): array
    {
        return [
            'flashcards.reviews',
            'questions.answers' => fn (Relation $query) => $query->where('user_id', $learner->id),
            'progress' => fn (Relation $query) => $query->where('user_id', $learner->id),
        ];
    }

    /**
     * @param  array<int, QuestionType>  $types
     * @return Collection<int, array{at: CarbonInterface, score: int}>
     */
    private function answerScores(Subtopic $subtopic, array $types): Collection
    {
        return $subtopic->questions
            ->filter(fn (Question $question): bool => in_array($question->type, $types, true))
            ->flatMap(fn (Question $question): Collection => $question->answers)
            // An answer that hasn't been graded yet says nothing about how well it is known.
            ->filter(fn (Answer $answer): bool => $answer->score !== null)
            ->map(fn (Answer $answer): array => ['at' => $answer->answered_at, 'score' => $answer->score])
            ->values();
    }

    /**
     * A facet's score is that of the last day it was practised. It holds when the last two days it
     * was practised both scored high: different days, so one lucky session doesn't count.
     *
     * @param  Collection<int, array{at: CarbonInterface, score: int}>  $scores
     * @return array{percent: ?int, holding: bool}
     */
    private function facet(Collection $scores, string $timezone): array
    {
        $scorePerDay = $scores
            ->groupBy(fn (array $score): string => $score['at']->copy()->setTimezone($timezone)->toDateString())
            ->map(fn (Collection $scoresOfDay): float => $scoresOfDay->avg('score'))
            ->sortKeysDesc()
            ->values();

        if ($scorePerDay->isEmpty()) {
            return ['percent' => null, 'holding' => false];
        }

        return [
            'percent' => (int) round($scorePerDay[0]),
            'holding' => $scorePerDay->count() >= 2
                && $scorePerDay[0] >= self::HOLDING_SCORE
                && $scorePerDay[1] >= self::HOLDING_SCORE,
        ];
    }

    /**
     * The average chance of remembering the reviewed cards right now, from 0 to 1, or null when no
     * card has been reviewed yet.
     *
     * @param  Collection<int, Flashcard>  $activeCards
     */
    private function expectedRecall(Collection $activeCards, CarbonImmutable $now): ?float
    {
        $recalls = $activeCards
            ->map(fn (Flashcard $flashcard): ?float => SummariseFlashcard::expectedRecall($flashcard, $now))
            ->filter(fn (?float $recall): bool => $recall !== null);

        return $recalls->isEmpty() ? null : $recalls->avg();
    }

    /**
     * The first day a card is due. A card that is overdue is due today.
     *
     * @param  Collection<int, Flashcard>  $activeCards
     */
    private function nextReviewOn(Collection $activeCards, CarbonImmutable $now): ?string
    {
        $firstDueAt = $activeCards->min('due_at');

        if ($firstDueAt === null) {
            return null;
        }

        $firstDueOn = $firstDueAt->copy()->setTimezone($now->getTimezone())->startOfDay();

        return $firstDueOn->max($now->startOfDay())->toDateString();
    }
}
