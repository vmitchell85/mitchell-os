<?php

namespace App\NativeComponents\Chat;

use App\NativeComponents\Chat\Concerns\CallsChatApi;
use App\Services\Api\ApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The signed-in user's conversations, most recently active first.
 */
class ConversationIndex extends NativeComponent
{
    use CallsChatApi;

    /** @var array<int, array{id: int, title: string, updated: string}> */
    public array $conversations = [];

    public bool $loaded = false;

    public string $error = '';

    public function navTitle(): string
    {
        return 'Chat';
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function onResume(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->error = '';

        $response = $this->request(fn (ApiClient $api): Response => $api->get('/conversations'));

        if ($response?->successful()) {
            $this->conversations = array_map(fn (array $conversation): array => [
                'id' => (int) $conversation['id'],
                'title' => (string) ($conversation['title'] ?? '') !== '' ? (string) $conversation['title'] : 'New conversation',
                'updated' => $this->easternTime($conversation['updated_at'] ?? null),
            ], $response->json('data', []));
        } elseif ($response !== null || ! $this->loaded) {
            $this->error = $this->errorMessage($response, 'Could not load your conversations.');
        }

        $this->loaded = true;
    }

    public function openConversation(int $id): void
    {
        $this->navigate("/chat/{$id}");
    }

    public function newConversation(): void
    {
        $this->navigate('/chat/new');
    }

    public function render(): View
    {
        return view('native.chat.conversation-index');
    }
}
