<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flashcards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtopic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('front');
            $table->text('back');
            $table->string('origin');
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('due_at');
            $table->unsignedInteger('interval_days')->default(0);
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('repetitions')->default(0);
            $table->unsignedInteger('lapses')->default(0);
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flashcards');
    }
};
