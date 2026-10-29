<?php

namespace Database\Factories;

use App\Enums\ReviewRating;
use App\Models\Flashcard;
use App\Models\FlashcardReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlashcardReview>
 */
class FlashcardReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flashcard_id' => Flashcard::factory(),
            'study_session_id' => null,
            'rating' => ReviewRating::Good,
            'interval_days_before' => 1,
            'interval_days_after' => 3,
            'reviewed_at' => now(),
        ];
    }
}
