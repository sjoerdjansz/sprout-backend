<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\Material;
use App\Models\Subtopic;
use App\Models\SubtopicSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtopicSource>
 */
class SubtopicSourceFactory extends Factory
{
    /**
     * Define the model's default state: the tutor's notes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subtopic_id' => Subtopic::factory(),
            'type' => SourceType::Tutor,
            'material_id' => null,
            'content' => fake()->paragraph(),
        ];
    }

    public function tutor(): static
    {
        return $this->state(['type' => SourceType::Tutor, 'material_id' => null]);
    }

    public function own(): static
    {
        return $this->state(['type' => SourceType::Own, 'material_id' => null]);
    }

    public function file(Material $material): static
    {
        return $this->state(['type' => SourceType::File, 'material_id' => $material->id]);
    }
}
