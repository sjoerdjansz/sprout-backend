<?php

namespace App\Actions;

use App\Enums\ContentOrigin;
use App\Models\Flashcard;
use App\Models\Subtopic;
use App\Models\User;

/**
 * Adds a flashcard the learner wrote to a subtopic. Like generated cards it is due straight away,
 * so it shows up in the next review.
 */
class AddFlashcard
{
    public function __construct(private SummariseFlashcard $summariseFlashcard) {}

    /**
     * @return array{flashcard: Flashcard, schedule: array<string, mixed>}
     */
    public function handle(Subtopic $subtopic, User $learner, string $front, string $back): array
    {
        $flashcard = $subtopic->flashcards()->create([
            'user_id' => $learner->id,
            'topic_id' => $subtopic->topic_id,
            'front' => $front,
            'back' => $back,
            'origin' => ContentOrigin::User,
            'due_at' => now(),
        ]);

        return [
            'flashcard' => $flashcard,
            'schedule' => $this->summariseFlashcard->handle($flashcard->load('reviews'), $learner->timezone()),
        ];
    }
}
