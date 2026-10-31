<?php

namespace App\Actions;

use App\Models\StudySession;

/**
 * Deletes a conversation with the tutor and its messages. Cards and questions made from it stay:
 * they only lose the link to the message they came from (the foreign key sets it to null).
 */
class DeleteConversation
{
    public function handle(StudySession $conversation): void
    {
        $conversation->delete();
    }
}
