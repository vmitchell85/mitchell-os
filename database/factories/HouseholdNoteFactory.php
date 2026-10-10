<?php

namespace Database\Factories;

use App\Models\HouseholdNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HouseholdNote>
 */
class HouseholdNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->randomElement([
                'The kids go to bed at 8 PM.',
                'We have soccer practice on Tuesdays.',
                'We like leftovers for lunch.',
            ]),
            'added_by' => null,
        ];
    }
}
