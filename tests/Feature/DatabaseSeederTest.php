<?php

namespace Tests\Feature;

use App\Actions\CreateStarterCategories;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
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
        $this->assertSame(CreateStarterCategories::NAMES, $ada->categories()->orderBy('id')->pluck('name')->all());
        $this->assertSame(['Science', 'Languages'], $ada->topics()->orderBy('id')->with('category')->get()->pluck('category.name')->all());

        $sam = User::where('email', 'sam@sprout.test')->sole();
        $this->assertNotNull($sam->settings);
        $this->assertSame(0, $sam->topics()->count());
        $this->assertSame(0, $sam->aiCredentials()->count());
        $this->assertSame(CreateStarterCategories::NAMES, $sam->categories()->orderBy('id')->pluck('name')->all());
    }

    public function test_the_active_learners_knowledge_map_shows_every_state(): void
    {
        Storage::fake('local');

        $this->seed(DatabaseSeeder::class);

        Sanctum::actingAs(User::where('email', 'ada@sprout.test')->sole());
        $subtopics = collect($this->getJson('/api/v1/topics')->assertOk()->json())->flatMap(fn (array $topic): array => $topic['subtopics']);

        $this->assertTrue($subtopics->contains('is_new', true), 'Ada should have a new subtopic');
        $this->assertTrue($subtopics->contains('slipping', true), 'Ada should have a slipping subtopic');
        $this->assertTrue($subtopics->contains(fn (array $subtopic): bool => $subtopic['facets']['cards']['holding']), 'Ada should have a facet that holds');
        $this->assertGreaterThan(0, $subtopics->sum('due_cards'), 'Ada should have cards due');
    }

    public function test_active_learner_gets_no_ai_key_when_none_is_configured(): void
    {
        Storage::fake('local');
        config(['services.openai.key' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::where('email', 'ada@sprout.test')->sole()->aiCredentials()->count());
    }
}
