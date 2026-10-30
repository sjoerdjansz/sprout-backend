<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Enums\SessionKind;
use App\Exceptions\AiRequestFailed;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Starts a chat with the tutor: a new exploration, or "Continue learning" on a saved topic.
 */
class StartConversation
{
    public function __construct(private AskTutor $askTutor) {}

    /**
     * @throws AiRequestFailed
     */
    public function handle(User $learner, string $firstMessage, ?Topic $topic): StudySession
    {
        $topic?->load('subtopics');

        $answer = $this->askTutor->handle(
            learner: $learner,
            savedTopic: $topic,
            draft: $topic === null ? AskTutor::emptyDraft() : $this->draftFromTopic($topic),
            recentMessages: collect(),
            newMessage: $firstMessage,
        );

        return DB::transaction(function () use ($learner, $firstMessage, $topic, $answer): StudySession {
            $conversation = $learner->studySessions()->create([
                'topic_id' => $topic?->id,
                'kind' => SessionKind::Learn,
                'draft' => $answer['draft'],
                'started_at' => now(),
            ]);

            $conversation->messages()->createMany([
                ['role' => MessageRole::User, 'content' => $firstMessage],
                [
                    'role' => MessageRole::Tutor,
                    'content' => $answer['reply'],
                    'model' => $answer['model'],
                    'input_tokens' => $answer['input_tokens'],
                    'output_tokens' => $answer['output_tokens'],
                ],
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
            'goal' => [
                'description' => $topic->goal,
                'deadline' => $topic->goal_deadline?->toDateString(),
                'recall_percentage' => $topic->goal_recall_percentage,
            ],
            'subtopics' => $topic->subtopics
                ->sortBy('position')
                ->map(fn (Subtopic $subtopic): array => ['title' => $subtopic->title, 'content' => $subtopic->content ?? ''])
                ->values()
                ->all(),
        ];
    }
}
