<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Enums\Interest;
use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Models\Message;
use App\Models\SubtopicProgress;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Gets the tutor's next reply and its updated draft of the topic. Stores nothing: the caller saves
 * the turn only once the reply is in, so a failed call leaves no half conversation behind.
 */
class AskTutor
{
    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * @param  array<string, mixed>  $draft  the tutor's draft so far (see emptyDraft())
     * @param  Collection<int, Message>  $recentMessages  the latest messages, oldest first
     * @return array{reply: string, draft: array<string, mixed>, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws AiRequestFailed
     */
    public function handle(User $learner, ?Topic $savedTopic, array $draft, Collection $recentMessages, string $newMessage): array
    {
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->chatModel(),
            reasoningEffort: $level->chatReasoningEffort(),
            instructions: file_get_contents(resource_path('prompts/tutor.md')),
            messages: [
                ['role' => 'developer', 'content' => 'Context: '.json_encode($this->context($learner, $savedTopic, $draft))],
                ...$recentMessages->map(fn (Message $message): array => [
                    'role' => $message->role === MessageRole::Tutor ? 'assistant' : 'user',
                    'content' => $message->content,
                ]),
                ['role' => 'user', 'content' => $newMessage],
            ],
            schemaName: 'tutor_reply',
            schema: self::replySchema(),
            maxCompletionTokens: config('ai.chat.max_completion_tokens'),
            timeoutSeconds: config('ai.chat.timeout_seconds'),
            learner: $learner,
            kind: AiCallKind::TutorReply,
        );

        return [
            'reply' => $completion['content']['reply'],
            'draft' => $completion['content']['draft'],
            'model' => $completion['model'],
            'input_tokens' => $completion['input_tokens'],
            'output_tokens' => $completion['output_tokens'],
        ];
    }

    /**
     * The draft of a conversation that hasn't settled anything yet.
     *
     * @return array{title: null, category: null, goal: array{description: null, deadline: null, recall_percentage: null}, subtopics: array{}}
     */
    public static function emptyDraft(): array
    {
        return [
            'title' => null,
            'category' => null,
            'goal' => ['description' => null, 'deadline' => null, 'recall_percentage' => null],
            'subtopics' => [],
        ];
    }

    /**
     * What the tutor knows about the learner and the topic. No name, email or id: the provider doesn't
     * need to know who the learner is.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function context(User $learner, ?Topic $savedTopic, array $draft): array
    {
        return [
            'today' => now()->toDateString(),
            'learner' => [
                'role' => $learner->settings?->learner_role?->value,
                'interests' => $learner->settings?->interests?->map(fn (Interest $interest): string => $interest->value)->values() ?? [],
                'categories' => $learner->categories()->orderBy('id')->pluck('name'),
            ],
            'draft' => $draft,
            'saved_topic' => $savedTopic === null ? null : $this->savedTopicContext($learner, $savedTopic),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function savedTopicContext(User $learner, Topic $topic): array
    {
        $masteryPerSubtopic = SubtopicProgress::query()
            ->where('user_id', $learner->id)
            ->whereIn('subtopic_id', $topic->subtopics->pluck('id'))
            ->pluck('mastery', 'subtopic_id');

        return [
            'title' => $topic->title,
            'goal' => [
                'description' => $topic->goal,
                'deadline' => $topic->goal_deadline?->toDateString(),
                'recall_percentage' => $topic->goal_recall_percentage,
            ],
            'subtopics' => $topic->subtopics->map(fn ($subtopic): array => [
                'title' => $subtopic->title,
                'mastery_percentage' => $masteryPerSubtopic[$subtopic->id] ?? 0,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function replySchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reply', 'draft'],
            'properties' => [
                'reply' => ['type' => 'string'],
                'draft' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title', 'category', 'goal', 'subtopics'],
                    'properties' => [
                        'title' => $nullableString,
                        'category' => $nullableString,
                        'goal' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['description', 'deadline', 'recall_percentage'],
                            'properties' => [
                                'description' => $nullableString,
                                'deadline' => $nullableString,
                                'recall_percentage' => ['type' => ['integer', 'null']],
                            ],
                        ],
                        'subtopics' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['title', 'content'],
                                'properties' => [
                                    'title' => ['type' => 'string'],
                                    'content' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
