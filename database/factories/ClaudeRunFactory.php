<?php

namespace Database\Factories;

use App\Models\ClaudeRun;
use App\Models\User;
use App\Services\Claude\ClaudeRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClaudeRun>
 */
class ClaudeRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'feature' => 'chat',
            'model' => 'sonnet',
            'status' => ClaudeRunStatus::Succeeded,
            'session_id' => fake()->uuid(),
            'cost_usd' => fake()->randomFloat(6, 0.0001, 0.1),
            'input_tokens' => fake()->numberBetween(100, 20000),
            'output_tokens' => fake()->numberBetween(10, 2000),
            'duration_ms' => fake()->numberBetween(500, 30000),
            'error' => null,
        ];
    }

    /**
     * Indicate that the run is still in progress.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClaudeRunStatus::Running,
            'session_id' => null,
            'cost_usd' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'duration_ms' => null,
        ]);
    }

    /**
     * Indicate that the run failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClaudeRunStatus::Failed,
            'session_id' => null,
            'cost_usd' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'error' => 'Claude exited with code 1: Not logged in',
        ]);
    }
}
