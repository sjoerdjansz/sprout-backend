<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns for the knowledge map (specs/knowledge-map.md): categories per learner and one on every
 * topic, notes on a topic, a description on a subtopic, and the highest mastery a subtopic reached.
 */
return new class extends Migration
{
    /**
     * Copied from CreateStarterCategories so this migration keeps working if that list changes later.
     */
    private const STARTER_CATEGORIES = ['Science', 'Technology', 'Maths', 'Languages', 'History', 'Arts & culture'];

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable()->after('description');
        });

        Schema::table('subtopics', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
        });

        Schema::table('subtopic_progress', function (Blueprint $table) {
            $table->unsignedTinyInteger('peak_mastery')->default(0)->after('mastery');
        });

        $this->backfill();

        Schema::table('topics', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subtopic_progress', function (Blueprint $table) {
            $table->dropColumn('peak_mastery');
        });

        Schema::table('subtopics', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('topics', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('notes');
        });

        Schema::dropIfExists('categories');
    }

    /**
     * Existing learners get the starter categories, and their topics go under Science: every topic
     * needs one, and there is nothing to tell which would fit better.
     */
    private function backfill(): void
    {
        foreach (DB::table('users')->pluck('id') as $userId) {
            foreach (self::STARTER_CATEGORIES as $name) {
                DB::table('categories')->insert(['user_id' => $userId, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            }

            $scienceId = DB::table('categories')->where('user_id', $userId)->where('name', 'Science')->value('id');
            DB::table('topics')->where('user_id', $userId)->update(['category_id' => $scienceId]);
        }

        DB::table('subtopic_progress')->update(['peak_mastery' => DB::raw('mastery')]);
    }
};
