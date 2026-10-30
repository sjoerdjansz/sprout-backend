<?php

namespace App\Enums;

/**
 * How capable (and costly) the AI behind the tutor is. The models per level live in config/ai.php,
 * so learners never see model names.
 */
enum AiLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /**
     * The model that writes the tutor's chat replies.
     */
    public function chatModel(): string
    {
        return config("ai.levels.{$this->value}.chat_model");
    }

    /**
     * The stronger model that turns subtopics into flashcards and questions, because those need to be right.
     */
    public function generationModel(): string
    {
        return config("ai.levels.{$this->value}.generation_model");
    }
}
