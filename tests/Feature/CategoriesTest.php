<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_learner_sees_only_their_own_categories_oldest_first(): void
    {
        $learner = User::factory()->create();
        $science = Category::factory()->for($learner)->create(['name' => 'Science']);
        $music = Category::factory()->for($learner)->create(['name' => 'Music']);
        Category::factory()->create(['name' => 'Someone else’s']);
        Sanctum::actingAs($learner);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertExactJson([
                ['id' => $science->id, 'name' => 'Science'],
                ['id' => $music->id, 'name' => 'Music'],
            ]);
    }

    public function test_categories_need_a_token(): void
    {
        $this->getJson('/api/v1/categories')->assertUnauthorized();
    }
}
