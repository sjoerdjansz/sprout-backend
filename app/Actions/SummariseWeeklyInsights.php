<?php

namespace App\Actions;

use App\Enums\QuestionType;
use App\Enums\ReviewRating;
use App\Models\Answer;
use App\Models\FlashcardReview;
use App\Models\Message;
use App\Models\StudySession;
use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The dashboard's "This week" row (specs/weekly-insights.md): the streak, the recall rate compared with
 * the week before and the active study time with the topics it went to. A week is the last 7 days,
 * today included, in the learner's timezone. Everything is worked out from the raw reviews, answers and
 * practice sessions.
 */
class SummariseWeeklyInsights
{
    /**
     * A longer pause between two moments of practice means the learner was away: it isn't study time.
     */
    public const IDLE_AFTER_SECONDS = 15 * 60;

    public const DAYS_IN_A_WEEK = 7;

    public function __construct(private CalculateStreak $calculateStreak) {}

    /**
     * @return array{from: string, to: string, streak: array{current_days: int, best_days: int}, recall_rate: array{percentage: ?int, previous_percentage: ?int, change_points: ?int}, study_time: array{minutes: int, topics: list<array{id: int, title: string, minutes: int}>}}
     */
    public function handle(User $learner): array
    {
        $timezone = $learner->timezone();
        $endOfToday = CarbonImmutable::now($timezone)->endOfDay();
        $weekStart = $endOfToday->subDays(self::DAYS_IN_A_WEEK - 1)->startOfDay();
        $previousWeekStart = $weekStart->subDays(self::DAYS_IN_A_WEEK);

        $recallThisWeek = $this->recallPercentage($learner, $weekStart, $endOfToday);
        $recallLastWeek = $this->recallPercentage($learner, $previousWeekStart, $weekStart->subDay()->endOfDay());
        $studySeconds = $this->studySecondsPerTopic($learner, $weekStart, $endOfToday);

        return [
            'from' => $weekStart->toDateString(),
            'to' => $endOfToday->toDateString(),
            'streak' => $this->calculateStreak->handle($learner),
            'recall_rate' => [
                'percentage' => $recallThisWeek,
                'previous_percentage' => $recallLastWeek,
                // From the rounded percentages, so "+3 points" matches the numbers the learner sees.
                'change_points' => $recallThisWeek !== null && $recallLastWeek !== null ? $recallThisWeek - $recallLastWeek : null,
            ],
            'study_time' => [
                'minutes' => intdiv((int) array_sum($studySeconds), 60),
                'topics' => $this->topicsByTime($studySeconds),
            ],
        ];
    }

    /**
     * Of the first rating of a card on a day and the first scored answer to a quiz question on a day,
     * the share that was remembered: a card not rated "again", a question right at the first try. Later
     * tries that day come after seeing the answer, so they aren't recall. Null without any.
     */
    private function recallPercentage(User $learner, CarbonImmutable $from, CarbonImmutable $until): ?int
    {
        $timezone = $learner->timezone();

        $cardsRemembered = FlashcardReview::query()
            ->whereIn('flashcard_id', $learner->flashcards()->select('id'))
            ->whereBetween('reviewed_at', [$from->utc(), $until->utc()])
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->get(['flashcard_id', 'rating', 'reviewed_at'])
            ->unique(fn (FlashcardReview $review): string => $review->flashcard_id.'@'.CarbonImmutable::parse($review->reviewed_at)->setTimezone($timezone)->toDateString())
            ->map(fn (FlashcardReview $review): bool => $review->rating !== ReviewRating::Again);

        // Explanations (Feynman) are checked for understanding, not recall, and an unscored answer is a
        // wrong first pick waiting for its second try: neither counts.
        $questionsRemembered = $learner->answers()
            ->whereHas('question', fn ($query) => $query->whereIn('type', [QuestionType::MultipleChoice, QuestionType::Open]))
            ->whereNotNull('score')
            ->whereBetween('answered_at', [$from->utc(), $until->utc()])
            ->orderBy('answered_at')
            ->orderBy('id')
            ->get(['question_id', 'is_correct', 'answered_at'])
            ->unique(fn (Answer $answer): string => $answer->question_id.'@'.CarbonImmutable::parse($answer->answered_at)->setTimezone($timezone)->toDateString())
            ->map(fn (Answer $answer): bool => $answer->is_correct === true);

        $outcomes = $cardsRemembered->merge($questionsRemembered);

        if ($outcomes->isEmpty()) {
            return null;
        }

        return (int) round($outcomes->filter()->count() / $outcomes->count() * 100);
    }

    /**
     * Active time in the practice sessions that started in the week, per topic id (0 for a moment
     * without a topic). Each stretch between two moments of practice goes to the topic of the card or
     * question at its end; a stretch longer than IDLE_AFTER_SECONDS is a pause and doesn't count.
     *
     * @return array<int, int> seconds per topic id
     */
    private function studySecondsPerTopic(User $learner, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $sessions = $learner->practiceSessions()
            ->whereBetween('started_at', [$from->utc(), $until->utc()])
            ->with(['flashcardReviews.flashcard:id,topic_id', 'answers.question:id,topic_id', 'messages:id,study_session_id,created_at'])
            ->get();

        $secondsPerTopic = [];

        foreach ($sessions as $session) {
            $moments = $this->momentsOf($session);

            for ($index = 1; $index < $moments->count(); $index++) {
                $seconds = $moments[$index]['at']->getTimestamp() - $moments[$index - 1]['at']->getTimestamp();

                if ($seconds > self::IDLE_AFTER_SECONDS) {
                    continue;
                }

                $topicId = $moments[$index]['topic_id'] ?? 0;
                $secondsPerTopic[$topicId] = ($secondsPerTopic[$topicId] ?? 0) + $seconds;
            }
        }

        return $secondsPerTopic;
    }

    /**
     * The start of a session and everything practised in it, oldest first, each with its topic.
     *
     * @return Collection<int, array{at: CarbonImmutable, topic_id: ?int}>
     */
    private function momentsOf(StudySession $session): Collection
    {
        return collect([['at' => $session->started_at, 'topic_id' => $session->topic_id]])
            ->merge($session->flashcardReviews->map(fn (FlashcardReview $review): array => ['at' => $review->reviewed_at, 'topic_id' => $review->flashcard->topic_id]))
            ->merge($session->answers->map(fn (Answer $answer): array => ['at' => $answer->answered_at, 'topic_id' => $answer->question->topic_id]))
            // The open session talks about the session's own topic.
            ->merge($session->messages->map(fn (Message $message): array => ['at' => $message->created_at, 'topic_id' => $session->topic_id]))
            ->map(fn (array $moment): array => ['at' => CarbonImmutable::parse($moment['at']), 'topic_id' => $moment['topic_id']])
            ->sortBy(fn (array $moment): int => $moment['at']->getTimestamp())
            ->values();
    }

    /**
     * The topics with at least a minute of study time, most time first.
     *
     * @param  array<int, int>  $secondsPerTopic
     * @return list<array{id: int, title: string, minutes: int}>
     */
    private function topicsByTime(array $secondsPerTopic): array
    {
        unset($secondsPerTopic[0]);
        $titles = Topic::whereKey(array_keys($secondsPerTopic))->pluck('title', 'id');

        return collect($secondsPerTopic)
            ->map(fn (int $seconds, int $topicId): array => ['id' => $topicId, 'title' => $titles[$topicId], 'minutes' => intdiv($seconds, 60)])
            ->filter(fn (array $topic): bool => $topic['minutes'] >= 1)
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }
}
