<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Finds the category a topic goes in: one of the learner's own by id, or by name. A name they
 * already have, in any capitalisation, reuses that category instead of making a duplicate.
 */
class ResolveCategory
{
    /**
     * Exactly one of the two is given; the Form Request makes sure of that and that the id is the learner's.
     */
    public function handle(User $learner, ?int $categoryId, ?string $categoryName): Category
    {
        if ($categoryId !== null) {
            return $learner->categories()->findOrFail($categoryId);
        }

        $name = trim((string) $categoryName);

        // Compared in PHP because SQLite and MariaDB disagree on case-insensitive collation.
        $existing = $learner->categories()->get()->first(
            fn (Category $category): bool => Str::lower($category->name) === Str::lower($name),
        );

        return $existing ?? $learner->categories()->create(['name' => $name]);
    }
}
