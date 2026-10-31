<?php

namespace App\Enums;

/**
 * The kinds of AI call a learner can cause, as their usage in settings counts them (specs/settings.md).
 */
enum AiCallKind: string
{
    case TutorReply = 'tutor_reply';
    // Checking an open answer in the retrieval quiz (CheckOpenAnswer).
    case AnswerCheck = 'answer_check';
    // Feedback on an Explain it explanation (CheckExplanation).
    case ExplainFeedback = 'explain_feedback';
    case CardGeneration = 'card_generation';
    // Reading a topic's source to suggest subtopics (SuggestSubtopics).
    case SubtopicSuggestions = 'subtopic_suggestions';
}
