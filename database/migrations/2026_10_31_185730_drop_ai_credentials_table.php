<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Learners no longer bring their own AI key: Sprout uses its own (specs/settings.md, "No BYOK").
 * The stored keys are dropped with the table, so rolling back gives an empty table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_credentials');
    }

    public function down(): void
    {
        Schema::create('ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('encrypted_api_key');
            $table->string('key_last_four', 4);
            $table->string('model', 100)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
        });
    }
};
