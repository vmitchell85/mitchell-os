<?php

namespace App\Jobs;

use App\Actions\Chat\ReplyProgress;
use App\Actions\Chat\ReplyToConversation;
use App\Models\Conversation;
use App\Services\Claude\ClaudeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Write Claude's reply in the background, so it carries on when the person
 * leaves the chat page. The page follows along through ReplyProgress.
 */
class AnswerChatMessage implements ShouldQueue
{
    use Queueable;

    /**
     * A failed reply is retried by the person, who can see why it failed.
     */
    public int $tries = 1;

    /**
     * Long enough for the longest Claude run, which the run's own timeout ends first.
     */
    public int $timeout;

    public function __construct(public Conversation $conversation)
    {
        $this->timeout = (int) config('claude.timeout') + 30;
    }

    public function handle(ReplyToConversation $replyToConversation): void
    {
        try {
            $replyToConversation->handle($this->conversation);
        } catch (ClaudeException $exception) {
            report($exception);
        }
    }

    /**
     * The worker died or timed out before Claude finished.
     */
    public function failed(?Throwable $exception): void
    {
        if (app(ReplyToConversation::class)->unansweredMessage($this->conversation) !== null) {
            ReplyProgress::for($this->conversation)->fail(__('The reply was interrupted. Try again.'));
        }
    }
}
