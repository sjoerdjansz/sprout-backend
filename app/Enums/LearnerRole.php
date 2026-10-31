<?php

namespace App\Enums;

/**
 * What brings a learner to Sprout ("What brings you to Sprout?" in settings). The tutor adapts its tone
 * and pace to it; resources/prompts/tutor.md says how.
 */
enum LearnerRole: string
{
    case Student = 'student';
    case Professional = 'professional';
    case CareerSwitcher = 'career_switcher';
    case Curious = 'curious';
    case Teacher = 'teacher';
    case LanguageLearner = 'language_learner';
}
