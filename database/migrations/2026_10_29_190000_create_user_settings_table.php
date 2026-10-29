<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves the sign up preferences off users, so users is only about the account.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('interests')->nullable();
            $table->boolean('wants_daily_reminder')->default(false);
            $table->string('timezone', 64)->default('UTC');
            $table->unsignedSmallInteger('daily_goal_cards')->default(20);
            $table->time('reminder_time')->nullable();
            $table->timestamps();
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('user_settings')->insert([
                'user_id' => $user->id,
                'interests' => $user->interests,
                'wants_daily_reminder' => $user->wants_daily_reminder,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['interests', 'wants_daily_reminder']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('interests')->nullable()->after('email_verified_at');
            $table->boolean('wants_daily_reminder')->default(false)->after('interests');
        });

        DB::table('user_settings')->orderBy('id')->each(function (object $settings): void {
            DB::table('users')->where('id', $settings->user_id)->update([
                'interests' => $settings->interests,
                'wants_daily_reminder' => $settings->wants_daily_reminder,
            ]);
        });

        Schema::dropIfExists('user_settings');
    }
};
