<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The practice sessions on the subtopic page's Sessions tab, latest first: the sessions about this
 * subtopic, and every session in which one of its cards was rated or one of its questions answered. A
 * session in which nothing was practised isn't listed.
 */
class ListSubtopicPracticeSessions
{
    public function __construct(private SummarisePracticeSession $summarisePracticeSession) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        return $learner->practiceSessions()
            ->where(function (Builder $query) use ($subtopic): void {
                $query
                    ->where(fn (Builder $about): Builder => $about
                        ->where('subtopic_id', $subtopic->id)
                        ->where(fn (Builder $practised): Builder => $practised
                            ->has('flashcardReviews')
                            ->orHas('answers')
                            ->orHas('messages')))
                    ->orWhereHas('flashcardReviews.flashcard', fn (Builder $card): Builder => $card->where('subtopic_id', $subtopic->id))
                    ->orWhereHas('answers.question', fn (Builder $question): Builder => $question->where('subtopic_id', $subtopic->id));
            })
            ->latest('started_at')
            ->latest('id')
            ->get()
            ->map(fn ($session): array => $this->summarisePracticeSession->handle($session, $learner))
            ->all();
    }
}
