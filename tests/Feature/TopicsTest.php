<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_topic_in_one_of_your_categories(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        Sanctum::actingAs($learner);

        $response = $this->postJson('/api/v1/topics', [
            'title' => 'Astronomy',
            'description' => 'Stars and planets.',
            'notes' => 'From the planetarium.',
            'category_id' => $science->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Astronomy')
            ->assertJsonPath('description', 'Stars and planets.')
            ->assertJsonPath('notes', 'From the planetarium.')
            ->assertJsonPath('category', ['id' => $science->id, 'name' => 'Science'])
            ->assertJsonPath('goal', ['description' => null, 'deadline' => null, 'recall_percentage' => null])
            ->assertJsonPath('subtopics', []);

        $topic = Topic::sole();
        $this->assertTrue($topic->user->is($learner));
        $this->assertSame($response->json('id'), $topic->id);
    }

    public function test_naming_a_category_reuses_one_you_have_or_makes_a_new_one(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        Category::factory()->create(['name' => 'Music']);
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_name' => ' science '])
            ->assertCreated()
            ->assertJsonPath('category.id', $science->id);

        $this->postJson('/api/v1/topics', ['title' => 'Jazz', 'category_name' => 'Music'])
            ->assertCreated()
            ->assertJsonPath('category.name', 'Music');

        $this->assertSame(['Science', 'Music'], $learner->categories()->orderBy('id')->pluck('name')->all());
    }

    public function test_a_topic_needs_a_name_and_exactly_one_category_of_your_own(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create();
        $someoneElsesCategory = Category::factory()->create();
        Sanctum::actingAs($learner);

        $this->postJson('/api/v1/topics', ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Give your topic a name.',
                'category_id' => 'Pick a category, or name a new one.',
            ]);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_id' => $science->id, 'category_name' => 'Space'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'Pick a category or name a new one, not both.']);

        $this->postJson('/api/v1/topics', ['title' => 'Astronomy', 'category_id' => $someoneElsesCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => "We couldn't find that category. Pick one from the list."]);

        $this->postJson('/api/v1/topics', ['title' => str_repeat('a', 256), 'category_name' => str_repeat('b', 61), 'notes' => str_repeat('c', 30001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Keep the topic name under 255 characters.',
                'category_name' => 'Keep the category name under 60 characters.',
                'notes' => 'Keep your notes under 30,000 characters.',
            ]);

        $this->assertDatabaseCount('topics', 0);
    }

    public function test_editing_a_topic_changes_its_details_and_category_but_keeps_its_goal_and_subtopics(): void
    {
        $learner = User::factory()->create();
        $topic = Topic::factory()->for($learner)->create(['title' => 'Astronmy', 'goal' => 'Name the planets']);
        Subtopic::factory()->for($topic)->create(['title' => 'Planets']);
        Sanctum::actingAs($learner);

        $response = $this->putJson("/api/v1/topics/{$topic->id}", [
            'title' => 'Astronomy',
            'description' => null,
            'notes' => 'New notes',
            'category_name' => 'Space',
        ]);

        $response->assertOk()
            ->assertJsonPath('title', 'Astronomy')
            ->assertJsonPath('description', null)
            ->assertJsonPath('notes', 'New notes')
            ->assertJsonPath('category.name', 'Space')
            ->assertJsonPath('goal.description', 'Name the planets')
            ->assertJsonPath('subtopics.0.title', 'Planets');

        $this->assertSame('Space', $topic->fresh()->category->name);
    }

    public function test_someone_elses_topic_cannot_be_edited(): void
    {
        $learner = User::factory()->create();
        $someoneElsesTopic = Topic::factory()->create(['title' => 'Theirs']);
        Sanctum::actingAs($learner);

        $this->putJson("/api/v1/topics/{$someoneElsesTopic->id}", ['title' => 'Mine', 'category_name' => 'Science'])->assertNotFound();

        $this->assertSame('Theirs', $someoneElsesTopic->fresh()->title);
    }

    public function test_topics_need_a_token(): void
    {
        $this->postJson('/api/v1/topics', ['title' => 'Astronomy'])->assertUnauthorized();
        $this->putJson('/api/v1/topics/1', ['title' => 'Astronomy'])->assertUnauthorized();
    }
}
