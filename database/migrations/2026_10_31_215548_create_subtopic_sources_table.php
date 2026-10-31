<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A subtopic's notes become sources of their own (specs/subtopic-sources.md): the tutor's notes, the learner's
 * own notes and the parts of uploaded files. The existing notes are copied over; subtopics.content is dropped
 * in a later migration, once nothing reads it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subtopic_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subtopic_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('material_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();

            $table->unique(['subtopic_id', 'material_id']);
        });

        $subtopics = DB::table('subtopics')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->get(['id', 'topic_id', 'title', 'content', 'updated_at']);

        foreach ($subtopics as $subtopic) {
            DB::table('subtopic_sources')->insert([
                'subtopic_id' => $subtopic->id,
                'type' => $this->cameFromChat($subtopic) ? 'tutor' : 'own',
                'material_id' => null,
                'content' => $subtopic->content,
                'created_at' => $subtopic->updated_at,
                'updated_at' => $subtopic->updated_at,
            ]);
        }
    }

    /**
     * Notes saved from a conversation are in the draft it was saved with, or gave cards that point at a chat
     * message. Everything else was typed or pasted by the learner.
     */
    private function cameFromChat(object $subtopic): bool
    {
        $savedDrafts = DB::table('study_sessions')
            ->where('topic_id', $subtopic->topic_id)
            ->where('kind', 'learn')
            ->whereNotNull('ended_at')
            ->whereNotNull('draft')
            ->pluck('draft');

        foreach ($savedDrafts as $draft) {
            foreach (json_decode($draft, true)['subtopics'] ?? [] as $draftSubtopic) {
                if (mb_strtolower(trim($draftSubtopic['title'] ?? '')) === mb_strtolower(trim($subtopic->title))) {
                    return true;
                }
            }
        }

        return DB::table('flashcards')->where('subtopic_id', $subtopic->id)->whereNotNull('source_message_id')->exists()
            || DB::table('questions')->where('subtopic_id', $subtopic->id)->whereNotNull('source_message_id')->exists();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subtopic_sources');
    }
};
