<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Claude\ClaudeCli;
use App\Services\Claude\ClaudeRequest;
use App\Services\Claude\MessageRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Replace a conversation's placeholder title (its truncated first message)
 * with a short summary written by Claude.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Queueable;

    /**
     * A title is nice to have, so a failure keeps the placeholder rather than retrying.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Conversation $conversation) {}

    public function handle(ClaudeCli $claude): void
    {
        $firstMessage = $this->conversation->messages()
            ->where('role', MessageRole::User)
            ->oldest('id')
            ->first();

        if ($firstMessage === null) {
            return;
        }

        $result = $claude->ask(new ClaudeRequest(
            prompt: Str::limit($firstMessage->content, 2000),
            feature: 'chat-title',
            user: $this->conversation->user,
            systemPrompt: 'Write a title of at most six words for a chat that starts with the message you are given. Do not answer the message.',
            model: 'haiku',
            jsonSchema: [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string']],
                'required' => ['title'],
            ],
        ));

        $this->conversation->update([
            'title' => Str::limit(trim($result->structured['title'] ?? '', " \"'"), 60) ?: $this->conversation->title,
        ]);
    }
}
