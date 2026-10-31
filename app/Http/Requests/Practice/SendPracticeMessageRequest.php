<?php

namespace App\Http\Requests\Practice;

use App\Http\Requests\Conversations\SendMessageRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A message to the tutor in the open session of a practice session; the same rules as in a conversation.
 */
class SendPracticeMessageRequest extends FormRequest
{
    /**
     * Ownership is checked when the session is looked up: someone else's is a 404.
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
            'message' => SendMessageRequest::MESSAGE_RULES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SendMessageRequest::MESSAGE_MESSAGES;
    }
}
