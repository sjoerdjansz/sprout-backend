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
        Schema::create('subtopic_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtopic_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('mastery')->default(0);
            $table->timestamp('last_studied_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'subtopic_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subtopic_progress');
    }
};
