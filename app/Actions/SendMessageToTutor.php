<?php

namespace App\Actions;

use App\Enums\MessageRole;
use App\Exceptions\AiRequestFailed;
use App\Models\Material;
use App\Models\StudySession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendMessageToTutor
{
    public const ALREADY_SAVED_MESSAGE = 'This conversation is saved. Use "Continue learning" to add to it.';

    public function __construct(private AskTutor $askTutor) {}

    /**
     * @param  Collection<int, Material>  $materials  the learner's files sent with the message, read and not sent before
     *
     * @throws ValidationException when the conversation is already saved.
     * @throws AiRequestFailed
     */
    public function handle(StudySession $conversation, string $message, Collection $materials): void
    {
        if ($conversation->isSaved()) {
            throw ValidationException::withMessages(['message' => self::ALREADY_SAVED_MESSAGE]);
        }

        $recentMessages = $conversation->messages()
            ->with('materials')
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
            newMaterials: $materials,
        );

        DB::transaction(function () use ($conversation, $message, $materials, $answer): void {
            $userMessage = $conversation->messages()->create(['role' => MessageRole::User, 'content' => $message]);
            Material::whereKey($materials->modelKeys())->update(['message_id' => $userMessage->id]);

            $conversation->messages()->create([
                'role' => MessageRole::Tutor,
                'content' => $answer['reply'],
                'model' => $answer['model'],
                'input_tokens' => $answer['input_tokens'],
                'output_tokens' => $answer['output_tokens'],
            ]);

            // Touch as well, so an unchanged draft still moves the conversation up in the chat history.
            $conversation->fill(['draft' => $answer['draft']])->touch();
        });
    }
}
