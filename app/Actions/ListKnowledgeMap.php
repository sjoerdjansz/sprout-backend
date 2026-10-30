<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;

/**
 * The learner's whole library for the knowledge map: every topic that isn't archived, oldest first,
 * with its subtopics and the progress on each. Everything is loaded in one go so the rules can run
 * without a query per subtopic.
 */
class ListKnowledgeMap
{
    public function __construct(private SummariseTopic $summariseTopic) {}

    /**
     * @return array<int, array{topic: Topic, subtopics: array<int, array{subtopic: Subtopic, progress: array<string, mixed>}>}>
     */
    public function handle(User $learner): array
    {
        $learner->loadMissing('settings');

        return $learner->topics()
            ->whereNull('archived_at')
            ->with(SummariseTopic::relations($learner))
            ->orderBy('id')
            ->get()
            ->map(fn (Topic $topic): array => $this->summariseTopic->handle($topic, $learner))
            ->all();
    }
}
