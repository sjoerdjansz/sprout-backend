<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments in the chat (specs/chat-attachments.md) are uploaded before there is a topic, so a material
 * now belongs to a learner, can be sent with a message, and only gets its topic when the conversation is saved.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->after('topic_id')->constrained()->nullOnDelete();
            $table->string('error')->nullable()->after('status');
        });

        DB::table('materials')->update([
            'user_id' => DB::raw('(select topics.user_id from topics where topics.id = materials.topic_id)'),
        ]);

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->foreignId('topic_id')->nullable()->change();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('materials')->whereNull('topic_id')->delete();

        Schema::table('materials', function (Blueprint $table) {
            // MariaDB uses the index for the user_id foreign key, so the key goes first.
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('user_id');
            $table->foreignId('topic_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('message_id');
            $table->dropColumn('error');
        });
    }
};
