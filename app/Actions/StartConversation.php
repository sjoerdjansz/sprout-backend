<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Enums\SessionKind;
use App\Enums\SourceType;
use App\Exceptions\AiRequestFailed;
use App\Models\Material;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a chat with the tutor: a new exploration, or "Continue learning" on a saved topic.
 */
class StartConversation
{
    public function __construct(private AskTutor $askTutor) {}

    /**
     * @param  Collection<int, Material>  $materials  the learner's files sent with the first message, read and not sent before
     *
     * @throws AiRequestFailed
     */
    public function handle(User $learner, string $firstMessage, ?Topic $topic, Collection $materials): StudySession
    {
        $topic?->load('category', 'subtopics.sources');

        $answer = $this->askTutor->handle(
            learner: $learner,
            savedTopic: $topic,
            draft: $topic === null ? AskTutor::emptyDraft() : $this->draftFromTopic($topic),
            recentMessages: collect(),
            newMessage: $firstMessage,
            newMaterials: $materials,
        );

        return DB::transaction(function () use ($learner, $firstMessage, $topic, $materials, $answer): StudySession {
            $conversation = $learner->studySessions()->create([
                'topic_id' => $topic?->id,
                'kind' => SessionKind::Learn,
                'draft' => $answer['draft'],
                'started_at' => now(),
            ]);

            $userMessage = $conversation->messages()->create(['role' => MessageRole::User, 'content' => $firstMessage]);
            Material::whereKey($materials->modelKeys())->update(['message_id' => $userMessage->id]);

            $conversation->messages()->create([
                'role' => MessageRole::Tutor,
                'content' => $answer['reply'],
                'model' => $answer['model'],
                'input_tokens' => $answer['input_tokens'],
                'output_tokens' => $answer['output_tokens'],
            ]);

            return $conversation;
        });
    }

    /**
     * Continuing a topic starts from what is saved, so the tutor and the save review see all of it.
     *
     * @return array<string, mixed>
     */
    private function draftFromTopic(Topic $topic): array
    {
        return [
            'title' => $topic->title,
            'category' => $topic->category->name,
            'goal' => [
                'description' => $topic->goal,
                'deadline' => $topic->goal_deadline?->toDateString(),
                'recall_percentage' => $topic->goal_recall_percentage,
            ],
            'subtopics' => $topic->subtopics
                ->sortBy('position')
                // The tutor continues its own notes; a subtopic without them starts from the learner's.
                ->map(fn (Subtopic $subtopic): array => [
                    'title' => $subtopic->title,
                    'content' => $subtopic->sourceContent(SourceType::Tutor) ?? $subtopic->sourceContent(SourceType::Own) ?? '',
                ])
                ->values()
                ->all(),
        ];
    }
}
