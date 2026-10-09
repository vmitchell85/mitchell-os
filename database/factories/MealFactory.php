<?php

namespace Database\Factories;

use App\Models\Meal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->startOfDay(),
            'category' => 'Dinner',
            'name' => fake()->randomElement(['Spaghetti bolognese', 'Chicken curry', 'Fish pie', 'Veggie chilli', 'Roast chicken']),
            'description' => null,
            'note' => null,
            'approved_at' => now(),
            'added_by' => null,
            'skylight_id' => null,
            'skylight_date' => null,
            'repeats_in_skylight' => false,
            'skylight_snapshot' => null,
        ];
    }

    /**
     * Indicate that the meal is a planner suggestion awaiting approval.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => null,
        ]);
    }

    /**
     * Indicate that the meal was synced with Skylight and hasn't changed since.
     */
    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'skylight_id' => (string) fake()->unique()->numberBetween(10000, 99999),
        ])->afterMaking(function ($meal): void {
            $meal->skylight_date = $meal->date;
            $meal->skylight_snapshot = $meal->syncableState();
        });
    }
}
