<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for exploring a topic with the tutor (specs/tutor-chat.md): the tutor's draft on a
 * conversation, the topic's SMART goal, what was explained per subtopic, and the AI level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->json('draft')->nullable()->after('kind');
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->text('goal')->nullable()->after('description');
            $table->date('goal_deadline')->nullable()->after('goal');
            $table->unsignedTinyInteger('goal_recall_percentage')->nullable()->after('goal_deadline');
        });

        Schema::table('subtopics', function (Blueprint $table) {
            $table->text('content')->nullable()->after('title');
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('ai_level', 10)->default('medium')->after('interests');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('ai_level');
        });

        Schema::table('subtopics', function (Blueprint $table) {
            $table->dropColumn('content');
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn(['goal', 'goal_deadline', 'goal_recall_percentage']);
        });

        Schema::table('study_sessions', function (Blueprint $table) {
            $table->dropColumn('draft');
        });
    }
};
