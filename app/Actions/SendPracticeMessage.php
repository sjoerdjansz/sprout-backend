<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Models\Message;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends the learner's message in the open session of a practice session and saves it with the tutor's
 * reply. The messages belong to the practice session, so they never show up in the chat history.
 */
class SendPracticeMessage
{
    public const SESSION_ENDED_MESSAGE = 'This practice session has ended. Start a new one to keep practising.';

    public function __construct(private AskPracticeTutor $askPracticeTutor) {}

    /**
     * @return array{message: Message, reply: Message}
     *
     * @throws ValidationException when the session is finished.
     * @throws AiRequestFailed
     */
    public function handle(StudySession $session, User $learner, string $message): array
    {
        if ($session->ended_at !== null) {
            throw ValidationException::withMessages(['message' => self::SESSION_ENDED_MESSAGE]);
        }

        $recentMessages = $session->messages()
            ->latest('id')
            ->limit(config('ai.chat.history_messages'))
            ->get()
            ->reverse()
            ->values();

        $answer = $this->askPracticeTutor->handle($session, $learner, $recentMessages, $message);

        return DB::transaction(fn (): array => [
            'message' => $session->messages()->create(['role' => MessageRole::User, 'content' => $message]),
            'reply' => $session->messages()->create([
                'role' => MessageRole::Tutor,
                'content' => $answer['reply'],
                'technique' => $answer['technique'],
                'model' => $answer['model'],
                'input_tokens' => $answer['input_tokens'],
                'output_tokens' => $answer['output_tokens'],
            ]),
        ]);
    }
}
