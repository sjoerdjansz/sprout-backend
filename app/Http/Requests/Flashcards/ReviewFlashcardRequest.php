<?php

namespace App\Http\Requests\Flashcards;

use App\Enums\ReviewRating;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rating a flashcard in a practice session: how well the learner remembered it.
 */
class ReviewFlashcardRequest extends FormRequest
{
    /**
     * Ownership of the card is checked when it is looked up: someone else's is a 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', Rule::enum(ReviewRating::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Pick how well you remembered it: again, hard, good or easy.',
            'rating.enum' => 'Pick how well you remembered it: again, hard, good or easy.',
        ];
    }
}
