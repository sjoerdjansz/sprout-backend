<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The preferences on the settings page (specs/settings.md). Every column has a default, so existing
 * learners and sign up keep working without setting them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->string('learner_role', 20)->nullable()->after('interests');
            $table->string('avatar_color', 10)->default('sun')->after('learner_role');
            $table->unsignedSmallInteger('session_size')->default(20)->after('daily_goal_cards');
            $table->boolean('interleave_subtopics')->default(true)->after('session_size');
            $table->boolean('hint_before_answer')->default(true)->after('interleave_subtopics');
            $table->boolean('save_chats_as_material')->default(true)->after('hint_before_answer');
            $table->boolean('wants_streak_reminder')->default(false)->after('reminder_time');
            $table->boolean('wants_weekly_insights')->default(false)->after('wants_streak_reminder');
            $table->string('reminder_channel', 10)->default('email')->after('wants_weekly_insights');
            $table->string('theme', 10)->default('system')->after('reminder_channel');
            $table->boolean('reduce_motion')->default(false)->after('theme');
            $table->boolean('sound_effects')->default(true)->after('reduce_motion');
        });

        // The reminder row always shows a time, so every learner gets one.
        DB::table('user_settings')->whereNull('reminder_time')->update(['reminder_time' => '19:00:00']);

        Schema::table('user_settings', function (Blueprint $table) {
            $table->time('reminder_time')->default('19:00:00')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->time('reminder_time')->default(null)->nullable()->change();
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'learner_role',
                'avatar_color',
                'session_size',
                'interleave_subtopics',
                'hint_before_answer',
                'save_chats_as_material',
                'wants_streak_reminder',
                'wants_weekly_insights',
                'reminder_channel',
                'theme',
                'reduce_motion',
                'sound_effects',
            ]);
        });
    }
};
