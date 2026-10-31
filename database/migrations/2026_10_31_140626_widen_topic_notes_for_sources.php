<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A topic's notes can hold a whole source of up to 30,000 characters (specs/topic-from-source.md).
 * A MySQL text column holds 65,535 bytes, too few for 30,000 characters outside plain ASCII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->mediumText('notes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            $table->text('notes')->nullable()->change();
        });
    }
};
