<?php

namespace App\Http\Requests\Conversations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /** Shared with StartConversationRequest, so every message has the same limits. */
    public const MESSAGE_RULES = ['required', 'string', 'max:2000'];

    public const MESSAGE_MESSAGES = [
        'message.required' => 'Type a message first.',
        'message.string' => 'Type a message first.',
        'message.max' => 'Keep your message under 2,000 characters. Split it into a few messages.',
    ];

    /**
     * Ownership is checked when the conversation is looked up: someone else's is a 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => self::MESSAGE_RULES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::MESSAGE_MESSAGES;
    }
}
