<?php

namespace App\Actions;

use App\Enums\ContentOrigin;
use App\Enums\QuestionType;
use App\Models\Subtopic;

/**
 * Stores the flashcards and questions GeneratePracticeMaterial made for one subtopic. New cards are
 * due straight away, so they show up in the next review.
 */
class StorePracticeMaterial
{
    /**
     * @param  array{flashcards: array<int, array{front: string, back: string}>, questions: array<int, array{type: QuestionType, prompt: string, model_answer: ?string, options: array<int, array{label: string, is_correct: bool, explanation: ?string}>}>}  $material
     * @param  int|null  $sourceMessageId  the tutor message the material came from, if it came from a conversation
     * @return array{flashcards: int, questions: int} how many were added
     */
    public function handle(Subtopic $subtopic, int $learnerId, array $material, ?int $sourceMessageId): array
    {
        foreach ($material['flashcards'] as $flashcard) {
            $subtopic->flashcards()->create([
                'user_id' => $learnerId,
                'topic_id' => $subtopic->topic_id,
                'front' => $flashcard['front'],
                'back' => $flashcard['back'],
                'origin' => ContentOrigin::Ai,
                'source_message_id' => $sourceMessageId,
                'due_at' => now(),
            ]);
        }

        foreach ($material['questions'] as $generatedQuestion) {
            $question = $subtopic->questions()->create([
                'topic_id' => $subtopic->topic_id,
                'type' => $generatedQuestion['type'],
                'prompt' => $generatedQuestion['prompt'],
                'model_answer' => $generatedQuestion['model_answer'],
                'origin' => ContentOrigin::Ai,
                'source_message_id' => $sourceMessageId,
            ]);

            foreach ($generatedQuestion['options'] as $position => $option) {
                $question->options()->create([...$option, 'position' => $position + 1]);
            }
        }

        return [
            'flashcards' => count($material['flashcards']),
            'questions' => count($material['questions']),
        ];
    }
}
