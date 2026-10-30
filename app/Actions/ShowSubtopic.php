<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\User;

/**
 * One subtopic for the subtopic page: its progress as the knowledge map shows it, its topic and
 * category for the header, and how many cards, questions and conversations the tabs hold.
 */
class ShowSubtopic
{
    public function __construct(private SummariseSubtopicProgress $summariseSubtopicProgress) {}

    /**
     * @return array{subtopic: Subtopic, progress: array<string, mixed>, counts: array{flashcards: int, questions: int, sessions: int}}
     */
    public function handle(Subtopic $subtopic, User $learner): array
    {
        $subtopic->load(['topic.category', ...SummariseSubtopicProgress::relations($learner)]);

        return [
            'subtopic' => $subtopic,
            'progress' => $this->summariseSubtopicProgress->handle($subtopic, $learner->timezone()),
            'counts' => [
                'flashcards' => $subtopic->flashcards->count(),
                'questions' => $subtopic->questions->count(),
                // Conversations belong to a topic, not to one of its subtopics.
                'sessions' => $learner->conversations()->where('topic_id', $subtopic->topic_id)->count(),
            ],
        ];
    }
}
