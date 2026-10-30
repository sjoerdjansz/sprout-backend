<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Models\StudySession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendMessageToTutor
{
    public const ALREADY_SAVED_MESSAGE = 'This conversation is saved. Use "Continue learning" to add to it.';

    public function __construct(private AskTutor $askTutor) {}

    /**
     * @throws ValidationException when the conversation is already saved.
     * @throws AiRequestFailed
     */
    public function handle(StudySession $conversation, string $message): void
    {
        if ($conversation->isSaved()) {
            throw ValidationException::withMessages(['message' => self::ALREADY_SAVED_MESSAGE]);
        }

        $recentMessages = $conversation->messages()
            ->latest('id')
            ->limit(config('ai.chat.history_messages'))
            ->get()
            ->reverse()
            ->values();

        $answer = $this->askTutor->handle(
            learner: $conversation->user,
            savedTopic: $conversation->topic?->load('subtopics'),
            draft: $conversation->draft ?? AskTutor::emptyDraft(),
            recentMessages: $recentMessages,
            newMessage: $message,
        );

        DB::transaction(function () use ($conversation, $message, $answer): void {
            $conversation->messages()->createMany([
                ['role' => MessageRole::User, 'content' => $message],
                [
                    'role' => MessageRole::Tutor,
                    'content' => $answer['reply'],
                    'model' => $answer['model'],
                    'input_tokens' => $answer['input_tokens'],
                    'output_tokens' => $answer['output_tokens'],
                ],
            ]);

            // Touch as well, so an unchanged draft still moves the conversation up in the chat history.
            $conversation->fill(['draft' => $answer['draft']])->touch();
        });
    }
}
