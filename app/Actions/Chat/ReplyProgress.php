<?php

namespace App\Actions\Chat;

use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * What Claude has done so far on a conversation's reply, kept outside the
 * request that asked for it, so the chat page can show a reply that's
 * still being written after the person navigates away and comes back.
 */
class ReplyProgress
{
    /**
     * How long a failure stays visible, so the person sees why when they come back.
     */
    public const int ErrorSeconds = 86400;

    public function __construct(protected Conversation $conversation) {}

    public static function for(Conversation $conversation): self
    {
        return new self($conversation);
    }

    public function begin(): void
    {
        $this->put(['status' => null, 'text' => '', 'error' => null]);
    }

    /**
     * Show what Claude is doing, and break the text so what it wrote before
     * the tool call doesn't run into what it writes after.
     */
    public function usingTool(string $status): void
    {
        $progress = $this->get() ?? ['status' => null, 'text' => '', 'error' => null];

        $this->put([
            'status' => $status,
            'text' => $progress['text'] === '' ? '' : $progress['text']."\n\n",
            'error' => null,
        ]);
    }

    public function append(string $text): void
    {
        $progress = $this->get() ?? ['status' => null, 'text' => '', 'error' => null];

        $this->put(['text' => $progress['text'].$text] + $progress);
    }

    public function fail(string $error): void
    {
        Cache::put($this->key(), ['status' => null, 'text' => '', 'error' => $error], self::ErrorSeconds);
    }

    public function clear(): void
    {
        Cache::forget($this->key());
    }

    /**
     * @return array{status: string|null, text: string, error: string|null}|null
     */
    public function get(): ?array
    {
        return Cache::get($this->key());
    }

    /**
     * Whether a reply is being written right now.
     */
    public function isReplying(): bool
    {
        $progress = $this->get();

        return $progress !== null && $progress['error'] === null;
    }

    /**
     * Kept a little longer than a Claude run can take, so a reply whose
     * worker died stops looking busy on its own.
     *
     * @param  array{status: string|null, text: string, error: string|null}  $progress
     */
    protected function put(array $progress): void
    {
        Cache::put($this->key(), $progress, (int) config('claude.timeout') + 60);
    }

    protected function key(): string
    {
        return "chat-reply:{$this->conversation->id}";
    }
}
