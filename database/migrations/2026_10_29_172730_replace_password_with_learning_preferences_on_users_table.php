<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sign in is passwordless (magic links), so passwords are replaced by the
     * preferences the sign up form collects.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password', 'remember_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('interests')->nullable()->after('email_verified_at');
            $table->boolean('wants_daily_reminder')->default(false)->after('interests');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['interests', 'wants_daily_reminder']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->rememberToken();
        });
    }
};
