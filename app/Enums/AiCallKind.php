<?php

namespace App\Enums;

/**
 * The kinds of AI call a learner can cause, as their usage in settings counts them (specs/settings.md).
 */
enum AiCallKind: string
{
    case TutorReply = 'tutor_reply';
    // Checking open answers and Explain it feedback come with the Practice Hub; nothing logs them yet.
    case AnswerCheck = 'answer_check';
    case ExplainFeedback = 'explain_feedback';
    case CardGeneration = 'card_generation';
}
