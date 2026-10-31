<?php

namespace Database\Factories;

use App\Models\Subtopic;
use App\Models\SubtopicSource;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subtopic>
 */
class SubtopicFactory extends Factory
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
            'title' => fake()->words(3, true),
            'description' => null,
            'position' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * With notes from the chat (specs/subtopic-sources.md).
     */
    public function withTutorNotes(?string $notes = null): static
    {
        return $this->has(SubtopicSource::factory()->tutor()->state(['content' => $notes ?? fake()->paragraph()]), 'sources');
    }

    /**
     * With the learner's own notes.
     */
    public function withOwnNotes(?string $notes = null): static
    {
        return $this->has(SubtopicSource::factory()->own()->state(['content' => $notes ?? fake()->paragraph()]), 'sources');
    }
}
