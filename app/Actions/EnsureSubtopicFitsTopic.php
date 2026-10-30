<?php

namespace App\Actions;

use App\Models\Subtopic;
use App\Models\Topic;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A subtopic added to or moved into a topic needs a name the topic doesn't have yet (in any
 * capitalisation, as saving from the chat matches names that way) and room under the 30 subtopics.
 */
class EnsureSubtopicFitsTopic
{
    public const MAX_SUBTOPICS = 30;

    public const DUPLICATE_TITLE_MESSAGE = 'This topic already has a subtopic with that name. Pick another name.';

    public const TOPIC_FULL_MESSAGE = 'This topic has 30 subtopics, the most it can have. Add it to another topic.';

    /**
     * @param  Subtopic|null  $subtopic  the subtopic being edited, left out of the comparison
     *
     * @throws ValidationException
     */
    public function handle(Topic $topic, string $title, ?Subtopic $subtopic = null): void
    {
        $otherSubtopics = $topic->subtopics()->get()
            ->reject(fn (Subtopic $other): bool => $other->id === $subtopic?->id);

        if ($otherSubtopics->contains(fn (Subtopic $other): bool => Str::lower($other->title) === Str::lower($title))) {
            throw ValidationException::withMessages(['title' => self::DUPLICATE_TITLE_MESSAGE]);
        }

        if ($otherSubtopics->count() >= self::MAX_SUBTOPICS) {
            throw ValidationException::withMessages(['topic_id' => self::TOPIC_FULL_MESSAGE]);
        }
    }
}
