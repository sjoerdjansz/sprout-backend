<?php

namespace App\Enums;

/**
 * Where a subtopic's source comes from (specs/subtopic-sources.md). A subtopic has at most one tutor and one
 * own source, and one file source per uploaded file.
 */
enum SourceType: string
{
    // The tutor's notes from the conversation the subtopic was saved from; replaced by a later conversation.
    case Tutor = 'tutor';
    // The learner's own notes, the only source they can edit.
    case Own = 'own';
    // The part of an uploaded file that belongs to the subtopic, word for word.
    case File = 'file';
}
