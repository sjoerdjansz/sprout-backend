<?php

/*
 * The AI behind the tutor (specs/tutor-chat.md). Learners pick a level, never a model.
 * Every call uses Sprout's own key (services.openai.key, see ResolveAiKey).
 */
return [

    'levels' => [
        'low' => [
            'chat_model' => env('AI_LOW_CHAT_MODEL', 'gpt-5.4-mini'),
            'generation_model' => env('AI_LOW_GENERATION_MODEL', 'gpt-5.5'),
        ],
        'medium' => [
            'chat_model' => env('AI_MEDIUM_CHAT_MODEL', 'gpt-5.6-terra'),
            'generation_model' => env('AI_MEDIUM_GENERATION_MODEL', 'gpt-6-sol'),
        ],
        'high' => [
            'chat_model' => env('AI_HIGH_CHAT_MODEL', 'gpt-6.1-terra'),
            'generation_model' => env('AI_HIGH_GENERATION_MODEL', 'gpt-6.1-sol'),
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
