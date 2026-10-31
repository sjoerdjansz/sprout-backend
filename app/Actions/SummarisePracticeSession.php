<?php

namespace App\Actions;

use App\Enums\AnswerVerdict;
use App\Enums\QuestionType;
use App\Models\Answer;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A practice session as the session, its Done screen and the Sessions tab show it: the plan, what was
 * done so far, the XP it earned and the mastery of its subtopics before and after. Everything is worked
 * out from the reviews, answers and messages that point to the session, so nothing can drift apart.
 */
class SummarisePracticeSession
{
    public function __construct(
        private SummariseSubtopicProgress $summariseSubtopicProgress,
        private CalculateStreak $calculateStreak,
    ) {}

    /**
     * @return array{session: StudySession, is_finished: bool, last_activity_at: CarbonInterface, done: array<string, mixed>, xp_earned: int, streak_days: int, subtopics: array<int, array<string, mixed>>}
     */
    public function handle(StudySession $session, User $learner): array
    {
        $session->loadMissing(['topic', 'subtopic', 'flashcardReviews.flashcard', 'answers.question', 'messages']);

        $reviews = $session->flashcardReviews->sortBy('id')->values();
        [$explanations, $quizAnswers] = $session->answers
            ->sortBy('id')
            ->partition(fn (Answer $answer): bool => $answer->question->type === QuestionType::Feynman);
        $quizAnswers = $quizAnswers->values();
        $explanations = $explanations->values();

        return [
            'session' => $session,
            'is_finished' => self::isFinished($session, $learner),
            'last_activity_at' => $this->lastActivityAt($session, $reviews, $session->answers),
            'done' => [
                'flashcard_reviews' => $reviews->map(fn (FlashcardReview $review): array => [
                    'flashcard_id' => $review->flashcard_id,
                    'rating' => $review->rating->value,
                    'reviewed_at' => $review->reviewed_at,
                ])->all(),
                'answers' => $quizAnswers->map(fn (Answer $answer): array => [
                    'question_id' => $answer->question_id,
                    'option_id' => $answer->question_option_id,
                    'score' => $answer->score,
                    // A wrong first pick has no score yet; it reads as "not quite", as when it was sent.
                    'verdict' => $answer->score === null ? AnswerVerdict::NotQuite->value : AnswerVerdict::of($answer)->value,
                    'answered_at' => $answer->answered_at,
                ])->all(),
                'explanations' => $explanations->map(fn (Answer $answer): array => [
                    'question_id' => $answer->question_id,
                    // Only a first version is scored (ExplainSubtopic).
                    'version' => $answer->score === null ? ExplainSubtopic::SECOND_VERSION : ExplainSubtopic::FIRST_VERSION,
                    'score' => $answer->score,
                    'verdict' => $answer->score === null ? null : AnswerVerdict::of($answer)->value,
                    'answered_at' => $answer->answered_at,
                ])->all(),
                'messages' => $session->messages->sortBy('id')->map(fn (Message $message): array => [
                    'id' => $message->id,
                    'role' => $message->role->value,
                    'content' => $message->content,
                    'technique' => $message->technique?->value,
                    'created_at' => $message->created_at,
                ])->values()->all(),
                'message_count' => $session->messages->count(),
            ],
            'xp_earned' => $reviews->count() * RecordPracticeActivity::XP_PER_CARD
                + $quizAnswers->whereNotNull('score')->count() * RecordPracticeActivity::XP_PER_ANSWER
                + $explanations->whereNotNull('score')->count() * RecordPracticeActivity::XP_PER_EXPLANATION,
            'streak_days' => $this->calculateStreak->handle($learner),
            'subtopics' => $this->subtopics($session, $learner, $reviews, $session->answers),
        ];
    }

    /**
     * A session is finished once it was finished (or replaced by a new one), and also when the day it
     * started on is over in the learner's timezone: it can only be resumed on that day.
     */
    public static function isFinished(StudySession $session, User $learner): bool
    {
        $startOfToday = CarbonImmutable::now($learner->timezone())->startOfDay();

        return $session->ended_at !== null || $session->started_at->lt($startOfToday);
    }

    /**
     * Whether anything was practised in the session: a card rated, a question answered, an explanation
     * or a message.
     */
    public static function isEmpty(StudySession $session): bool
    {
        return ! $session->flashcardReviews()->exists()
            && ! $session->answers()->exists()
            && ! $session->messages()->exists();
    }

    /**
     * @param  Collection<int, FlashcardReview>  $reviews
     * @param  Collection<int, Answer>  $answers
     */
    private function lastActivityAt(StudySession $session, Collection $reviews, Collection $answers): CarbonInterface
    {
        $moments = collect([$session->started_at, $session->messages->max('created_at')])
            ->merge($reviews->pluck('reviewed_at'))
            ->merge($answers->pluck('answered_at'))
            ->filter()
            ->map(fn (mixed $moment): CarbonImmutable => CarbonImmutable::parse($moment));

        return $moments->max();
    }

    /**
     * The subtopic the session was about, and every subtopic practised in it, in topic order, with their
     * mastery when the session started and when it was finished (now, while it is still going).
     *
     * @param  Collection<int, FlashcardReview>  $reviews
     * @param  Collection<int, Answer>  $answers
     * @return array<int, array{id: int, title: string, mastery_before: int, mastery_after: int, next_review_on: ?string}>
     */
    private function subtopics(StudySession $session, User $learner, Collection $reviews, Collection $answers): array
    {
        $subtopicIds = collect([$session->subtopic_id])
            ->merge($reviews->map(fn (FlashcardReview $review): ?int => $review->flashcard->subtopic_id))
            ->merge($answers->map(fn (Answer $answer): ?int => $answer->question->subtopic_id))
            ->filter()
            ->unique()
            ->values();

        if ($subtopicIds->isEmpty()) {
            return [];
        }

        $timezone = $learner->timezone();

        return Subtopic::query()
            ->whereIn('id', $subtopicIds)
            ->with(SummariseSubtopicProgress::relations($learner))
            ->orderBy('position')
            ->get()
            ->map(function (Subtopic $subtopic) use ($session, $timezone): array {
                $progress = $this->summariseSubtopicProgress->handle($subtopic, $timezone);

                return [
                    'id' => $subtopic->id,
                    'title' => $subtopic->title,
                    'mastery_before' => $session->mastery_before[$subtopic->id] ?? 0,
                    'mastery_after' => $session->mastery_after[$subtopic->id] ?? $progress['mastery'],
                    'next_review_on' => $progress['next_review_on'],
                ];
            })
            ->all();
    }
}
