<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'added_by' => null,
            'name' => fake()->randomElement(['Milk', 'Eggs', 'Bread', 'Apples', 'Rice', 'Coffee']),
            'section' => null,
            'position' => fake()->numberBetween(1, 100),
            'completed_at' => null,
            'skylight_id' => null,
            'skylight_snapshot' => null,
        ];
    }

    /**
     * Indicate that the item has been ticked off.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the item was synced with Skylight and hasn't changed since.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'skylight_id' => (string) fake()->unique()->numberBetween(10000, 99999),
        ])->afterMaking(function ($item): void {
            $item->skylight_snapshot = $item->syncableState();
        });
    }
}
