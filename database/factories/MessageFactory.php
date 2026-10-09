<?php

namespace Database\Factories;

use App\Models\ClaudeRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Claude\MessageRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'claude_run_id' => null,
            'role' => MessageRole::User,
            'content' => fake()->paragraph(),
        ];
    }

    /**
     * Indicate that Claude wrote the message, as the result of a run.
     */
    public function fromAssistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Assistant,
            'claude_run_id' => ClaudeRun::factory(),
        ]);
    }
}
