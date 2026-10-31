<?php

namespace App\Actions;

use App\Enums\AiLevel;
use App\Enums\QuestionType;
use App\Exceptions\AiRequestFailed;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Turns subtopics into flashcards and questions with the level's stronger generation model.
 * Stores nothing; SaveConversationToKnowledgeMap saves the result.
 */
class GeneratePracticeMaterial
{
    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * Returns the material per subtopic, in the order the subtopics were given.
     *
     * @param  array{title: string, goal: string}  $topic
     * @param  array<int, array{title: string, content: string, existing_items: array<int, string>}>  $subtopics
     * @return array<int, array{flashcards: array<int, array{front: string, back: string}>, questions: array<int, array{type: QuestionType, prompt: string, model_answer: ?string, options: array<int, array{label: string, is_correct: bool, explanation: ?string}>}>}>
     *
     * @throws AiRequestFailed
     */
    public function handle(User $learner, array $topic, array $subtopics): array
    {
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->generationModel(),
            instructions: file_get_contents(resource_path('prompts/practice-material.md')),
            messages: [
                ['role' => 'user', 'content' => json_encode(['topic' => $topic, 'subtopics' => $subtopics])],
            ],
            schemaName: 'practice_material',
            schema: self::materialSchema(),
            maxCompletionTokens: config('ai.generation.max_completion_tokens'),
            timeoutSeconds: config('ai.generation.timeout_seconds'),
            safetyIdentifier: $learner->aiSafetyIdentifier(),
        );

        $generatedSubtopics = $completion['content']['subtopics'];

        // Material is matched to subtopics by position, so a different count can't be trusted.
        if (count($generatedSubtopics) !== count($subtopics)) {
            Log::warning('The AI returned practice material for a different number of subtopics.', [
                'expected' => count($subtopics),
                'received' => count($generatedSubtopics),
            ]);

            throw AiRequestFailed::unavailable();
        }

        return array_map(fn (array $generated): array => [
            'flashcards' => $generated['flashcards'],
            'questions' => $this->usableQuestions($generated['questions']),
        ], $generatedSubtopics);
    }

    /**
     * A multiple choice question needs at least two options with exactly one right answer; the schema
     * can't enforce that, so broken ones are left out rather than shown to the learner.
     *
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function usableQuestions(array $questions): array
    {
        $usable = [];

        foreach ($questions as $question) {
            $type = QuestionType::from($question['type']);
            $options = $type === QuestionType::MultipleChoice ? $question['options'] : [];
            $correctOptions = array_filter($options, fn (array $option): bool => $option['is_correct']);

            if ($type === QuestionType::MultipleChoice && (count($options) < 2 || count($correctOptions) !== 1)) {
                Log::warning('Left out a generated multiple choice question without exactly one right answer.', [
                    'prompt' => $question['prompt'],
                ]);

                continue;
            }

            $usable[] = [
                'type' => $type,
                'prompt' => $question['prompt'],
                'model_answer' => $type === QuestionType::MultipleChoice ? null : $question['model_answer'],
                'options' => $options,
            ];
        }

        return $usable;
    }

    /**
     * @return array<string, mixed>
     */
    private static function materialSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['subtopics'],
            'properties' => [
                'subtopics' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'flashcards', 'questions'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'flashcards' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['front', 'back'],
                                    'properties' => [
                                        'front' => ['type' => 'string'],
                                        'back' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'questions' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['type', 'prompt', 'model_answer', 'options'],
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => array_column(QuestionType::cases(), 'value')],
                                        'prompt' => ['type' => 'string'],
                                        'model_answer' => ['type' => ['string', 'null']],
                                        'options' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['label', 'is_correct', 'explanation'],
                                                'properties' => [
                                                    'label' => ['type' => 'string'],
                                                    'is_correct' => ['type' => 'boolean'],
                                                    'explanation' => ['type' => ['string', 'null']],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
