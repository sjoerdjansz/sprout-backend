<?php

namespace App\Actions;

use App\Models\Material;
use App\Models\Message;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a topic for good (specs/knowledge-map.md). Its subtopics, flashcards with their reviews,
 * questions with their answers and the learner's progress go with it, and so do the conversations
 * and practice sessions about it: the foreign keys cascade from topics. Uploaded files live on
 * disk, so they are removed here. The days practised (XP and streak) stay: they record effort, not
 * the topic.
 */
class DeleteTopic
{
    public function handle(Topic $topic): void
    {
        $materials = Material::query()
            ->where('topic_id', $topic->id)
            // A file sent in a conversation about the topic that was never saved has no topic yet.
            ->orWhereIn('message_id', Message::whereIn('study_session_id', $topic->studySessions()->select('id'))->select('id'))
            ->get(['id', 'storage_path']);

        DB::transaction(function () use ($topic, $materials): void {
            Material::whereKey($materials->modelKeys())->delete();
            $topic->delete();
        });

        // Files go once the rows are gone, so a failed delete never leaves rows pointing at missing files.
        Storage::disk('local')->delete($materials->pluck('storage_path')->all());
    }
}
