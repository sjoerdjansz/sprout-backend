<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\User;

/**
 * Works out a subtopic's mastery again after the learner practised it, and stores it with the highest
 * mastery reached (which "slipping" needs). Mastery is the average of the three facets, with a facet
 * that wasn't practised yet counting as 0: mastered needs all three, so mastery does too.
 */
class RecalculateSubtopicMastery
{
    public function __construct(private SummariseSubtopicProgress $summariseSubtopicProgress) {}

    /**
     * Returns the subtopic's progress with the new mastery, as the knowledge map shows it.
     *
     * @return array<string, mixed>
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        $subtopic->load(SummariseSubtopicProgress::relations($learner));
        $progress = $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone());

        $facetPercentages = array_map(fn (array $facet): int => $facet['percent'] ?? 0, $progress['facets']);
        $mastery = (int) round(array_sum($facetPercentages) / count($facetPercentages));

        $storedProgress = SubtopicProgress::query()->firstOrNew([
            'user_id' => $learner->id,
            'subtopic_id' => $subtopic->id,
        ]);
        $storedProgress->fill([
            'mastery' => $mastery,
            'peak_mastery' => max($storedProgress->peak_mastery ?? 0, $mastery),
            'last_studied_at' => now(),
        ])->save();

        return [...$progress, 'mastery' => $mastery];
    }
}
