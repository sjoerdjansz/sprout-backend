<?php

namespace App\Actions;

use App\Models\SubtopicSource;

/**
 * Removes one source from a subtopic (specs/subtopic-sources.md): the tutor's notes, the learner's own notes
 * or the part of a file. The flashcards and questions made from it stay, and nothing new is made. A file
 * part only leaves this subtopic: the file stays with the topic.
 */
class DeleteSubtopicSource
{
    public function handle(SubtopicSource $source): void
    {
        $source->delete();
    }
}
