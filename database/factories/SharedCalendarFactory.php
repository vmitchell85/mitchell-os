<?php

namespace Database\Factories;

use App\Models\SharedCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SharedCalendar>
 */
class SharedCalendarFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'calendar_id' => fake()->unique()->uuid(),
            'title' => fake()->randomElement(['Home', 'Work', 'Family', 'School']),
            'label' => null,
        ];
    }
}
