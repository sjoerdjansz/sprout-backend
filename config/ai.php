<?php

/*
 * The AI behind the tutor (specs/tutor-chat.md). Learners pick a level, never a model.
 * Every call uses Sprout's own key (services.openai.key, see ResolveAiKey).
 *
 * Reasoning effort: every configured model accepts low, medium, high and xhigh (checked 2026-10-31;
 * none of them accepts minimal, and gpt-6.1-sol not none). The chat thinks less so replies come quickly;
 * generation stays at medium, roughly the models' default, because high made one call about 1.5 times as
 * slow and a save of up to 30 subtopics has to fit in the generation timeout and token budget.
 */
return [

    'levels' => [
        'low' => [
            'chat_model' => env('AI_LOW_CHAT_MODEL', 'gpt-5.4-mini'),
            'generation_model' => env('AI_LOW_GENERATION_MODEL', 'gpt-5.5'),
            'chat_reasoning_effort' => env('AI_LOW_CHAT_REASONING_EFFORT', 'low'),
            'generation_reasoning_effort' => env('AI_LOW_GENERATION_REASONING_EFFORT', 'medium'),
        ],
        'medium' => [
            'chat_model' => env('AI_MEDIUM_CHAT_MODEL', 'gpt-5.6-terra'),
            'generation_model' => env('AI_MEDIUM_GENERATION_MODEL', 'gpt-6-sol'),
            'chat_reasoning_effort' => env('AI_MEDIUM_CHAT_REASONING_EFFORT', 'low'),
            'generation_reasoning_effort' => env('AI_MEDIUM_GENERATION_REASONING_EFFORT', 'medium'),
        ],
        'high' => [
            'chat_model' => env('AI_HIGH_CHAT_MODEL', 'gpt-6.1-terra'),
            'generation_model' => env('AI_HIGH_GENERATION_MODEL', 'gpt-6.1-sol'),
            'chat_reasoning_effort' => env('AI_HIGH_CHAT_REASONING_EFFORT', 'medium'),
            'generation_reasoning_effort' => env('AI_HIGH_GENERATION_REASONING_EFFORT', 'medium'),
        ],
    ],

    'chat' => [
        // Older messages aren't sent: what matters from them is in the tutor's draft.
        'history_messages' => 10,
        // Generous, because reasoning tokens count too; the prompt keeps replies short.
        'max_completion_tokens' => 4000,
        'timeout_seconds' => 60,
    ],

    'generation' => [
        'max_completion_tokens' => 16000,
        'timeout_seconds' => 150,
    ],

];
