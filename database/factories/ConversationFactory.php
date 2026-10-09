<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
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
            'title' => fake()->sentence(4),
            'claude_session_id' => null,
            'model' => null,
        ];
    }

    /**
     * Indicate that the conversation has a Claude session that can be resumed.
     */
    public function withSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'claude_session_id' => fake()->uuid(),
        ]);
    }
}
