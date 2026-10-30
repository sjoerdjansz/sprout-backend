<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A topic as the knowledge map shows it: the topic with its category, and every subtopic, in order,
 * with the learner's progress on it.
 */
class SummariseTopic
{
    public function __construct(private SummariseSubtopicProgress $summariseSubtopicProgress) {}

    /**
     * @return array{topic: Topic, subtopics: array<int, array{subtopic: Subtopic, progress: array<string, mixed>}>}
     */
    public function handle(Topic $topic, User $learner): array
    {
        $topic->loadMissing(self::relations($learner));
        $timezone = $learner->timezone();

        return [
            'topic' => $topic,
            'subtopics' => $topic->subtopics
                ->map(fn (Subtopic $subtopic): array => [
                    'subtopic' => $subtopic,
                    'progress' => $this->summariseSubtopicProgress->handle($subtopic, $timezone),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * What a summary needs loaded. ListKnowledgeMap eager-loads these for all topics at once.
     *
     * @return array<string|int, mixed>
     */
    public static function relations(User $learner): array
    {
        $relations = [
            'category',
            'subtopics' => fn (Relation $query) => $query->orderBy('position')->orderBy('id'),
        ];

        foreach (SummariseSubtopicProgress::relations($learner) as $key => $value) {
            if (is_int($key)) {
                $relations[] = 'subtopics.'.$value;
            } else {
                $relations['subtopics.'.$key] = $value;
            }
        }

        return $relations;
    }
}
