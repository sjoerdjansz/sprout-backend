<?php

namespace App\Actions;

use App\Enums\AiCallKind;
use App\Enums\AiLevel;
use App\Exceptions\AiRequestFailed;
use App\Models\Material;
use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Finds the part of an uploaded file that belongs to each of a topic's subtopics (specs/chat-attachments.md).
 * The file is cut into numbered blocks here and the AI only answers with block numbers, so an excerpt is
 * always the learner's own text, word for word, and the answer stays small however long the file is.
 * Stores nothing: SplitSourcesOverSubtopics adds the excerpts to the notes.
 */
class SplitMaterialOverSubtopics
{
    /** A paragraph without a sentence end in sight is cut here, so one block never holds a whole file. */
    private const MAX_BLOCK_CHARACTERS = 1_500;

    /** pdftotext fills lines to about 80 characters, so a shorter line ends a paragraph or is a heading. */
    private const SHORT_LINE_CHARACTERS = 50;

    public function __construct(
        private ResolveAiKey $resolveAiKey,
        private RequestAiCompletion $requestAiCompletion,
    ) {}

    /**
     * @param  Collection<int, Subtopic>  $subtopics  all of the topic's subtopics, in order
     * @return array<int, array<int, string>> the file's blocks per subtopic id, in the file's order, only for the
     *                                        subtopics that got a part of it
     *
     * @throws AiRequestFailed
     */
    public function handle(Topic $topic, Material $material, Collection $subtopics): array
    {
        $blocks = $this->blocks((string) $material->extracted_text);
        $learner = $topic->user;
        $level = $learner->settings?->ai_level ?? AiLevel::Medium;

        // Reading a whole file and placing every part of it well is the harder task, so the stronger model.
        $completion = $this->requestAiCompletion->handle(
            apiKey: $this->resolveAiKey->handle(),
            model: $level->generationModel(),
            reasoningEffort: $level->generationReasoningEffort(),
            instructions: file_get_contents(resource_path('prompts/split-source.md')),
            messages: [
                ['role' => 'user', 'content' => json_encode([
                    'topic' => ['title' => $topic->title, 'goal' => $topic->goal ?? ''],
                    'subtopics' => $subtopics->map(fn (Subtopic $subtopic): array => [
                        'title' => $subtopic->title,
                        'notes' => $subtopic->sourceText(),
                    ])->values()->all(),
                    'blocks' => collect($blocks)->map(fn (string $text, int $number): array => [
                        'number' => $number,
                        'text' => $text,
                    ])->values()->all(),
                ])],
            ],
            schemaName: 'split_source',
            schema: self::splitSchema(),
            maxCompletionTokens: config('ai.generation.max_completion_tokens'),
            timeoutSeconds: config('ai.generation.timeout_seconds'),
            learner: $learner,
            // Counted with suggesting subtopics: both split a source over subtopics ("Sources split up" in usage).
            kind: AiCallKind::SubtopicSuggestions,
        );

        return $this->excerpts($completion['content']['subtopics'], $subtopics, $blocks);
    }

    /**
     * The file's text as blocks numbered from 1, in order: its paragraphs, headings and list items.
     * pdftotext wraps lines at the page width and leaves no blank line between paragraphs, so wrapped lines
     * are joined again until a line ends a sentence, is short (a heading or the last line of a paragraph),
     * or is followed by a blank line.
     *
     * @return array<int, string>
     */
    public function blocks(string $text): array
    {
        $blocks = [];
        $currentBlock = '';

        foreach (preg_split('/\R|\f/u', $text) as $line) {
            $line = trim($line);

            if ($line === '' || ($currentBlock !== '' && $this->startsBlock($line))) {
                $blocks[] = $currentBlock;
                $currentBlock = '';
            }

            if ($line === '') {
                continue;
            }

            $currentBlock = $currentBlock === '' ? $line : $currentBlock.' '.$line;

            if ($this->endsBlock($line) || Str::length($currentBlock) >= self::MAX_BLOCK_CHARACTERS) {
                $blocks[] = $currentBlock;
                $currentBlock = '';
            }
        }

        $blocks = array_values(array_filter([...$blocks, $currentBlock], fn (string $block): bool => $block !== ''));

        if ($blocks === []) {
            return [];
        }

        return array_combine(range(1, count($blocks)), $blocks);
    }

    /**
     * A Markdown heading or list item never continues the line before it.
     */
    private function startsBlock(string $line): bool
    {
        return preg_match('/^(#{1,6}\s|[-*+]\s|\d+[.)]\s)/u', $line) === 1;
    }

    private function endsBlock(string $line): bool
    {
        return preg_match('/[.!?:]["\'”’)]?$/u', $line) === 1 || Str::length($line) < self::SHORT_LINE_CHARACTERS;
    }

    /**
     * The prompt asks for known titles and each block once; this makes sure of it, so the answer can be trusted.
     *
     * @param  array<int, array{title: string, blocks: array<int, int>}>  $answer
     * @param  Collection<int, Subtopic>  $subtopics
     * @param  array<int, string>  $blocks
     * @return array<int, array<int, string>>
     */
    private function excerpts(array $answer, Collection $subtopics, array $blocks): array
    {
        $subtopicsByTitle = $subtopics->keyBy(fn (Subtopic $subtopic): string => Str::lower(trim($subtopic->title)));
        $usedBlocks = [];
        $excerpts = [];

        foreach ($answer as $item) {
            $subtopic = $subtopicsByTitle->get(Str::lower(trim($item['title'])));

            if ($subtopic === null) {
                continue;
            }

            $numbers = array_values(array_unique(array_filter(
                $item['blocks'],
                fn (int $number): bool => isset($blocks[$number]) && ! isset($usedBlocks[$number]),
            )));
            sort($numbers);

            if ($numbers === []) {
                continue;
            }

            foreach ($numbers as $number) {
                $usedBlocks[$number] = true;
            }

            $excerpts[$subtopic->id] = [
                ...($excerpts[$subtopic->id] ?? []),
                ...array_map(fn (int $number): string => $blocks[$number], $numbers),
            ];
        }

        return $excerpts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function splitSchema(): array
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
                        'required' => ['title', 'blocks'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'blocks' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
