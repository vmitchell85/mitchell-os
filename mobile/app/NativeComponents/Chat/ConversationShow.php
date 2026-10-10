<?php

namespace App\NativeComponents\Chat;

use App\NativeComponents\Chat\Concerns\CallsChatApi;
use App\Services\Api\ApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * One conversation. The API answers a message while the request is open, so
 * sending happens on the next poll tick (letting the "thinking" indicator
 * paint first). If the request times out, Claude still finishes on the
 * server and we poll the conversation until its reply lands.
 */
class ConversationShow extends NativeComponent
{
    use CallsChatApi;

    /**
     * Polls before giving up on a reply (about three minutes).
     */
    public const int MaxPolls = 90;

    public ?int $conversationId = null;

    public string $title = 'New conversation';

    /** @var array<int, array{id: int|null, role: string, content: string, time: string}> */
    public array $messages = [];

    public string $draft = '';

    /** Text waiting to be sent on the next tick. */
    public string $pendingText = '';

    /** Whether the next tick should retry the reply to the last message. */
    public bool $retrying = false;

    /** Whether we are waiting on Claude's reply. */
    public bool $waiting = false;

    public bool $canRetry = false;

    public bool $loaded = false;

    public int $polls = 0;

    public string $error = '';

    public function navTitle(): string
    {
        return $this->title;
    }

    public function mount(): void
    {
        $id = $this->param('id');

        if (is_numeric($id)) {
            $this->conversationId = (int) $id;
            $this->loadConversation();
        }

        $this->loaded = true;
    }

    public function onResume(): void
    {
        if ($this->conversationId !== null && ! $this->waiting) {
            $this->loadConversation();
        }
    }

    public function send(): void
    {
        $text = trim($this->draft);

        if ($text === '' || $this->waiting) {
            return;
        }

        $this->pendingText = $text;
        $this->draft = '';
        $this->error = '';
        $this->canRetry = false;
        $this->polls = 0;
        $this->waiting = true;
        $this->messages[] = ['id' => null, 'role' => 'user', 'content' => $text, 'time' => ''];
    }

    public function retry(): void
    {
        if ($this->conversationId === null || $this->waiting) {
            return;
        }

        $this->error = '';
        $this->canRetry = false;
        $this->polls = 0;
        $this->retrying = true;
        $this->waiting = true;
    }

    /**
     * Sends what is queued, or checks whether Claude's reply has landed.
     * Does nothing unless a reply is being waited on, which also ends the
     * polling when the screen is left (the component is unmounted).
     */
    #[Poll(2000)]
    public function tick(): void
    {
        if (! $this->waiting) {
            return;
        }

        if ($this->pendingText !== '' || $this->retrying) {
            $this->deliver();

            return;
        }

        $this->checkForReply();
    }

    public function loadConversation(): void
    {
        $response = $this->request(fn (ApiClient $api): Response => $api->get("/conversations/{$this->conversationId}"));

        if ($response?->successful()) {
            $this->applyConversation($response->json('data', []));

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not load this conversation.');
    }

    public function render(): View
    {
        return view('native.chat.conversation-show');
    }

    protected function deliver(): void
    {
        $text = $this->pendingText;
        $isRetry = $this->retrying;
        $this->pendingText = '';
        $this->retrying = false;

        $response = $this->request(function (ApiClient $api) use ($text, $isRetry): Response {
            if ($isRetry) {
                return $api->post("/conversations/{$this->conversationId}/reply");
            }

            return $this->conversationId === null
                ? $api->post('/conversations', ['message' => $text])
                : $api->post("/conversations/{$this->conversationId}/messages", ['message' => $text]);
        });

        if ($response === null) {
            $this->recoverFromTimeout();

            return;
        }

        if ($response->successful()) {
            $this->applyConversation($response->json('data', []));
            $this->waiting = false;

            return;
        }

        $this->waiting = false;

        if ($response->status() === 502 && $response->json('conversation_id') !== null) {
            $this->conversationId = (int) $response->json('conversation_id');
            $this->canRetry = true;
            $this->loadConversation();
            $this->error = $this->errorMessage($response, 'Claude could not answer.');

            return;
        }

        if ($response->status() === 422 && ! $isRetry) {
            $this->discardUnsentMessage($text);
        }

        $this->error = $this->errorMessage($response, 'Could not send your message.');
    }

    /**
     * The request went quiet, but the server saves the message first and
     * finishes the reply anyway, so keep polling for it. For a brand new
     * conversation we do not know its id yet, so find it in the list.
     */
    protected function recoverFromTimeout(): void
    {
        if ($this->conversationId !== null) {
            return;
        }

        $list = $this->request(fn (ApiClient $api): Response => $api->get('/conversations'));
        $latest = $list?->json('data.0');

        if (is_array($latest)) {
            $this->conversationId = (int) $latest['id'];

            return;
        }

        $this->waiting = false;
        $this->error = 'Could not reach the server. Check your connection and try again.';
    }

    protected function checkForReply(): void
    {
        $this->polls++;

        $response = $this->request(fn (ApiClient $api): Response => $api->get("/conversations/{$this->conversationId}"));

        if ($response?->successful()) {
            $this->applyConversation($response->json('data', []));

            $last = end($this->messages);

            if ($last !== false && $last['role'] === 'assistant') {
                $this->waiting = false;

                return;
            }
        }

        if ($this->polls >= self::MaxPolls) {
            $this->waiting = false;
            $this->canRetry = true;
            $this->error = 'Claude is taking longer than expected.';
        }
    }

    protected function discardUnsentMessage(string $text): void
    {
        $last = end($this->messages);

        if ($last !== false && $last['id'] === null) {
            array_pop($this->messages);
        }

        $this->draft = $text;
    }

    /**
     * @param  array<string, mixed>  $conversation
     */
    protected function applyConversation(array $conversation): void
    {
        if (isset($conversation['id'])) {
            $this->conversationId = (int) $conversation['id'];
        }

        if (($conversation['title'] ?? '') !== '') {
            $this->title = (string) $conversation['title'];
        }

        $this->messages = array_map(fn (array $message): array => [
            'id' => (int) $message['id'],
            'role' => (string) $message['role'],
            'content' => (string) $message['content'],
            'time' => $this->easternTime($message['created_at'] ?? null),
        ], $conversation['messages'] ?? []);
    }
}
