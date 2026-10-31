<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A subtopic's notes live in subtopic_sources now (specs/subtopic-sources.md), so its own column goes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subtopics', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }

    /**
     * Puts the tutor's notes and the learner's own notes back into one field; file parts stay as sources.
     */
    public function down(): void
    {
        Schema::table('subtopics', function (Blueprint $table) {
            $table->text('content')->nullable()->after('description');
        });

        $notesPerSubtopic = DB::table('subtopic_sources')
            ->whereIn('type', ['tutor', 'own'])
            ->orderByRaw("case type when 'tutor' then 1 else 2 end")
            ->get(['subtopic_id', 'content'])
            ->groupBy('subtopic_id');

        foreach ($notesPerSubtopic as $subtopicId => $sources) {
            DB::table('subtopics')->where('id', $subtopicId)->update([
                'content' => $sources->pluck('content')->implode("\n\n"),
            ]);
        }
    }
};
