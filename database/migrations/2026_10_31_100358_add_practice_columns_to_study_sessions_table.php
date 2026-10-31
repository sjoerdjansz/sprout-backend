<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for a practice session (specs/practice-session.md), which is a `review` study session: the
 * subtopic it is about (null for a whole topic), the plan the learner picked so a reload can resume it,
 * and the mastery of the scope's subtopics when it started and when it was finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->foreignId('subtopic_id')->nullable()->after('topic_id')->constrained()->cascadeOnDelete();
            $table->json('plan')->nullable()->after('draft');
            $table->json('mastery_before')->nullable()->after('plan');
            $table->json('mastery_after')->nullable()->after('mastery_before');
        });
    }

    public function down(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn(['plan', 'mastery_before', 'mastery_after']);
            $table->dropConstrainedForeignId('subtopic_id');
        });
    }
};
