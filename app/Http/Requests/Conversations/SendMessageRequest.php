<?php

namespace App\Http\Requests\Conversations;

use App\Enums\MaterialStatus;
use App\Models\User;
use Closure;
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
        'material_ids.array' => 'Attach up to 5 files per message.',
        'material_ids.max' => 'Attach up to 5 files per message.',
        'material_ids.*.integer' => self::MATERIAL_NOT_FOUND_MESSAGE,
        'material_ids.*.distinct' => 'You attached this file twice. Remove one.',
    ];

    private const MATERIAL_NOT_FOUND_MESSAGE = "We couldn't find that file. Upload it again.";

    /**
     * Files sent with a message (specs/chat-attachments.md): the learner's own, read, and not sent before.
     * Shared with StartConversationRequest.
     *
     * @return array<string, array<mixed>>
     */
    public static function materialRules(User $learner): array
    {
        return [
            'material_ids' => ['sometimes', 'array', 'max:5'],
            'material_ids.*' => [
                'integer',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($learner): void {
                    $material = $learner->materials()->find($value);

                    if ($material === null) {
                        $fail(self::MATERIAL_NOT_FOUND_MESSAGE);
                    } elseif ($material->isSent()) {
                        $fail('This file is already in a message. Upload it again to send it here.');
                    } elseif ($material->status === MaterialStatus::Processing) {
                        $fail('Wait until your file is ready, then send.');
                    } elseif ($material->status === MaterialStatus::Failed) {
                        $fail("That file couldn't be read. Remove it and try another.");
                    }
                },
            ],
        ];
    }

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
            ...self::materialRules($this->user()),
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
