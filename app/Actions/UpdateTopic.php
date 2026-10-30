<?php

namespace App\Actions;

use App\Models\Topic;
use Illuminate\Support\Facades\DB;

/**
 * Changes a topic's name, description, notes or category from the knowledge map. Its goal and
 * subtopics stay as they are.
 */
class UpdateTopic
{
    public function __construct(
        private ResolveCategory $resolveCategory,
        private SummariseTopic $summariseTopic,
    ) {}

    /**
     * @return array{topic: Topic, subtopics: array<int, array<string, mixed>>} the topic, as the knowledge map shows it
     */
    public function handle(Topic $topic, string $title, ?string $description, ?string $notes, ?int $categoryId, ?string $categoryName): array
    {
        DB::transaction(function () use ($topic, $title, $description, $notes, $categoryId, $categoryName): void {
            $topic->update([
                'category_id' => $this->resolveCategory->handle($topic->user, $categoryId, $categoryName)->id,
                'title' => $title,
                'description' => $description,
                'notes' => $notes,
            ]);
        });

        return $this->summariseTopic->handle($topic->unsetRelation('category'), $topic->user);
    }
}
