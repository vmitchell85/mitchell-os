<?php

namespace App\Services\Skylight;

/**
 * A planned meal ("meal sitting"). A repeating meal is one sitting with an
 * RRULE that occurs on several dates.
 */
final readonly class SkylightMeal
{
    /**
     * @param  list<string>  $dates  The dates (YYYY-MM-DD) it occurs on within the requested range.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $note,
        public ?SkylightMealCategory $category,
        public ?string $recipeId,
        public bool $isRecurring,
        public array $dates,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $recipe = $attributes['meal_recipe'] ?? null;

        return new self(
            id: (string) $attributes['id'],
            name: ($attributes['summary'] ?? '') ?: ($recipe['summary'] ?? ''),
            description: $attributes['description'] ?? '',
            note: $attributes['note'] ?? '',
            category: isset($attributes['meal_category']) ? SkylightMealCategory::fromArray($attributes['meal_category']) : null,
            recipeId: isset($recipe['id']) ? (string) $recipe['id'] : null,
            isRecurring: (bool) ($attributes['recurring'] ?? false),
            dates: array_values($attributes['instances'] ?? []),
        );
    }
}
