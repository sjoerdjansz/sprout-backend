<?php

namespace Database\Factories;

use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\Topic;
use App\Models\User;
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
            // The learner who owns the topic; an unsent file (no topic yet) gets a learner of its own.
            'user_id' => fn (array $attributes): int => $attributes['topic_id'] === null
                ? User::factory()->create()->id
                : Topic::findOrFail($attributes['topic_id'])->user_id,
            'original_filename' => 'notes.pdf',
            'storage_path' => 'materials/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
            'extracted_text' => fake()->paragraphs(3, true),
            'status' => MaterialStatus::Ready,
        ];
    }

    /**
     * Uploaded and read, but not sent with a message yet: no topic either.
     */
    public function unsent(): static
    {
        return $this->state(['topic_id' => null, 'message_id' => null]);
    }

    public function processing(): static
    {
        return $this->state(['status' => MaterialStatus::Processing, 'extracted_text' => null]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => MaterialStatus::Failed,
            'extracted_text' => null,
            'error' => "We couldn't read this file. Save it as a PDF and upload it again.",
        ]);
    }
}
