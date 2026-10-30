<?php

namespace App\Actions;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds a topic to the learner's library by hand, in an existing category or a new one. Nothing is
 * generated: the notes are kept for the learner, cards come from subtopics.
 */
class CreateTopic
{
    public function __construct(
        private ResolveCategory $resolveCategory,
        private SummariseTopic $summariseTopic,
    ) {}

    /**
     * @return array{topic: Topic, subtopics: array{}} the new topic, as the knowledge map shows it
     */
    public function handle(User $learner, string $title, ?string $description, ?string $notes, ?int $categoryId, ?string $categoryName): array
    {
        $topic = DB::transaction(fn (): Topic => $learner->topics()->create([
            'category_id' => $this->resolveCategory->handle($learner, $categoryId, $categoryName)->id,
            'title' => $title,
            'description' => $description,
            'notes' => $notes,
        ]));

        return $this->summariseTopic->handle($topic, $learner);
    }
}
