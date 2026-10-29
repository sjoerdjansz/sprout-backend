<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_an_active_and_a_new_learner(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => 'sk-test-key-ending-in-abcd']);

        $this->seed(DatabaseSeeder::class);

        $ada = User::where('email', 'ada@sprout.test')->sole();
        $this->assertSame(2, $ada->topics()->count());
        $this->assertTrue($ada->flashcards()->where('due_at', '<=', now())->exists(), 'Ada should have cards due');
        $this->assertTrue($ada->flashcards()->where('due_at', '>', now()->endOfDay())->exists(), 'Ada should have cards scheduled later');
        $this->assertTrue($ada->dailyActivities()->whereDate('activity_date', today())->exists(), 'Ada\'s streak should include today');
        $this->assertSame('abcd', $ada->aiCredentials()->sole()->key_last_four);
        Storage::disk('local')->assertExists('materials/seed/photosynthesis-notes.txt');

        $sam = User::where('email', 'sam@sprout.test')->sole();
        $this->assertNotNull($sam->settings);
        $this->assertSame(0, $sam->topics()->count());
        $this->assertSame(0, $sam->aiCredentials()->count());
    }

    public function test_active_learner_gets_no_ai_key_when_none_is_configured(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('email', 'ada@sprout.test')->sole()->aiCredentials()->count());
    }
}
