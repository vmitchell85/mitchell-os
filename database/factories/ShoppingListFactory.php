<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingList>
 */
class ShoppingListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Groceries', 'Costco', 'Hardware store']),
            'color' => fake()->hexColor(),
            'skylight_id' => null,
            'is_default' => false,
            'last_synced_at' => null,
        ];
    }

    /**
     * Indicate that the list mirrors a Skylight list.
     */
    public function fromSkylight(): static
    {
        return $this->state(fn (array $attributes) => [
            'skylight_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'last_synced_at' => now(),
        ]);
    }
}
