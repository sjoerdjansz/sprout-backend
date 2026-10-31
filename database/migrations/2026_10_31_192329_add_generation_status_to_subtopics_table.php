<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flashcards and questions are made in the background, so a subtopic says whether they are still coming
 * (specs/background-generation.md). Existing subtopics already have theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtopics', function (Blueprint $table) {
            $table->string('generation_status')->default('ready')->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('subtopics', function (Blueprint $table) {
            $table->dropColumn('generation_status');
        });
    }
};
