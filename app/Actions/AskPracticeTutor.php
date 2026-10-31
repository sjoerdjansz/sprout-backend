<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Enums\MessageRole;
use App\Enums\QuestionType;
use App\Enums\Technique;
use App\Exceptions\AiRequestFailed;
use App\Models\Answer;
use App\Models\Flashcard;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\SubtopicProgress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Gets the tutor's next reply in the open session of a practice session. This tutor knows the learner's
 * cards and open questions on what they practise, and asks before it tells. Stores nothing: the caller
 * saves the turn only once the reply is in.
 */
class AskPracticeTutor
{
    /** The weakest cards and questions are sent, so a large topic doesn't blow up the prompt. */
    private const MAX_FLASHCARDS = 50;

    private const MAX_OPEN_QUESTIONS = 20;

    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * @param  Collection<int, Message>  $recentMessages  the latest messages, oldest first
     * @return array{reply: string, technique: ?Technique, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws AiRequestFailed
     */
    public function handle(StudySession $session, User $learner, Collection $recentMessages, string $newMessage): array
    {
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->chatModel(),
            instructions: file_get_contents(resource_path('prompts/practice-tutor.md')),
            messages: [
                ['role' => 'developer', 'content' => 'Context: '.json_encode($this->context($session, $learner))],
                ...$recentMessages->map(fn (Message $message): array => [
                    'role' => $message->role === MessageRole::Tutor ? 'assistant' : 'user',
                    'content' => $message->content,
                ]),
                ['role' => 'user', 'content' => $newMessage],
            ],
            schemaName: 'practice_tutor_reply',
            schema: self::replySchema(),
            maxCompletionTokens: config('ai.chat.max_completion_tokens'),
            timeoutSeconds: config('ai.chat.timeout_seconds'),
            learner: $learner,
            kind: AiCallKind::TutorReply,
        );

        $technique = $completion['content']['technique'];

        return [
            'reply' => $completion['content']['reply'],
            'technique' => $technique === null ? null : Technique::tryFrom($technique),
            'model' => $completion['model'],
            'input_tokens' => $completion['input_tokens'],
            'output_tokens' => $completion['output_tokens'],
        ];
    }

    /**
     * What the tutor knows: the topic, the subtopic (null for a whole topic) and the learner's material on
     * it. No name, email or id: the provider doesn't need to know who the learner is.
     *
     * @return array<string, mixed>
     */
    private function context(StudySession $session, User $learner): array
    {
        $topic = $session->topic;
        $subtopics = $session->subtopic !== null
            ? new EloquentCollection([$session->subtopic])
            : $topic->subtopics()->orderBy('position')->get();
        $subtopicIds = $subtopics->pluck('id');
        $masteryPerSubtopic = SubtopicProgress::query()
            ->where('user_id', $learner->id)
            ->whereIn('subtopic_id', $subtopicIds)
            ->pluck('mastery', 'subtopic_id');
        $titlePerSubtopic = $subtopics->pluck('title', 'id');

        return [
            'today' => now()->toDateString(),
            'learner' => ['role' => $learner->settings?->learner_role?->value],
            'topic' => ['title' => $topic->title, 'goal' => $topic->goal],
            'subtopic' => $session->subtopic === null ? null : [
                'title' => $session->subtopic->title,
                'notes' => $session->subtopic->content,
                'mastery_percentage' => $masteryPerSubtopic[$session->subtopic->id] ?? 0,
            ],
            'subtopics' => $subtopics->map(fn (Subtopic $subtopic): array => [
                'title' => $subtopic->title,
                'mastery_percentage' => $masteryPerSubtopic[$subtopic->id] ?? 0,
            ])->values()->all(),
            'flashcards' => $this->flashcards($subtopicIds, $titlePerSubtopic),
            'open_questions' => $this->openQuestions($learner, $subtopicIds, $titlePerSubtopic),
        ];
    }

    /**
     * The unpaused cards, lowest expected recall first; never-reviewed cards count as 0.
     *
     * @param  Collection<int, int>  $subtopicIds
     * @param  Collection<int, string>  $titlePerSubtopic
     * @return array<int, array{subtopic: string, front: string, back: string, recall_percentage: ?int}>
     */
    private function flashcards(Collection $subtopicIds, Collection $titlePerSubtopic): array
    {
        $now = CarbonImmutable::now();

        return Flashcard::query()
            ->whereIn('subtopic_id', $subtopicIds)
            ->whereNull('suspended_at')
            ->with('reviews')
            ->get()
            ->map(function (Flashcard $flashcard) use ($now, $titlePerSubtopic): array {
                $recall = SummariseFlashcard::expectedRecall($flashcard, $now);

                return [
                    'subtopic' => $titlePerSubtopic[$flashcard->subtopic_id],
                    'front' => $flashcard->front,
                    'back' => $flashcard->back,
                    'recall_percentage' => $recall === null ? null : (int) round($recall * 100),
                ];
            })
            ->sortBy(fn (array $card): int => $card['recall_percentage'] ?? 0)
            ->take(self::MAX_FLASHCARDS)
            ->values()
            ->all();
    }

    /**
     * The open questions with how the learner did last time; unanswered and low-scoring ones first.
     *
     * @param  Collection<int, int>  $subtopicIds
     * @param  Collection<int, string>  $titlePerSubtopic
     * @return array<int, array{subtopic: string, question: string, model_answer: ?string, last_score: ?int}>
     */
    private function openQuestions(User $learner, Collection $subtopicIds, Collection $titlePerSubtopic): array
    {
        return Question::query()
            ->whereIn('subtopic_id', $subtopicIds)
            ->where('type', QuestionType::Open)
            ->with(['answers' => fn (Relation $query) => $query->where('user_id', $learner->id)->whereNotNull('score')])
            ->get()
            ->map(function (Question $question) use ($titlePerSubtopic): array {
                $latestAnswer = $question->answers->sortByDesc(fn (Answer $answer): array => [$answer->answered_at->getTimestamp(), $answer->id])->first();

                return [
                    'subtopic' => $titlePerSubtopic[$question->subtopic_id],
                    'question' => $question->prompt,
                    'model_answer' => $question->model_answer,
                    'last_score' => $latestAnswer?->score,
                ];
            })
            ->sortBy(fn (array $question): int => $question['last_score'] ?? -1)
            ->take(self::MAX_OPEN_QUESTIONS)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function replySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['reply', 'technique'],
            'properties' => [
                'reply' => ['type' => 'string'],
                'technique' => [
                    'type' => ['string', 'null'],
                    'enum' => [...array_map(fn (Technique $technique): string => $technique->value, Technique::cases()), null],
                ],
            ],
        ];
    }
}
