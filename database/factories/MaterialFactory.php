<?php

namespace Database\Factories;

use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'topic_id' => Topic::factory(),
            'original_filename' => 'notes.pdf',
            'storage_path' => 'materials/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'extracted_text' => fake()->paragraphs(3, true),
            'status' => MaterialStatus::Ready,
        ];
    }
}
