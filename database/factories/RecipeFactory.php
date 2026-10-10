<?php

namespace Database\Factories;

use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Lasagna', 'Chicken tacos', 'Beef chili', 'Mac and cheese']),
            'ingredients' => "1 lb ground beef\n1 onion\n1 can diced tomatoes",
            'steps' => "Brown the beef with the onion.\nAdd the tomatoes and simmer for 20 minutes.",
            'servings' => '4',
            'total_minutes' => 40,
            'notes' => null,
            'source_url' => null,
            'added_by' => null,
        ];
    }
}
