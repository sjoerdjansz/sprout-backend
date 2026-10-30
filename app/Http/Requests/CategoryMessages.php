<?php

namespace App\Http\Requests;

/**
 * Validation messages for picking a topic's category (`category_id`) or naming a new one
 * (`category_name`), shared by every request that files a topic.
 */
final class CategoryMessages
{
    public const MESSAGES = [
        'category_id.required_without' => 'Pick a category, or name a new one.',
        'category_id.integer' => "We couldn't find that category. Pick one from the list.",
        'category_id.exists' => "We couldn't find that category. Pick one from the list.",
        'category_id.prohibits' => 'Pick a category or name a new one, not both.',
        'category_name.string' => 'Give the new category a name.',
        'category_name.max' => 'Keep the category name under 60 characters.',
    ];
}
