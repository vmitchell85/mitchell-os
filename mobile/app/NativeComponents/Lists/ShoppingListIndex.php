<?php

namespace App\NativeComponents\Lists;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class ShoppingListIndex extends NativeComponent
{
    /** @var list<array{id: int, name: string, color: string|null, is_default: bool, pending_items_count: int|null}> */
    public array $lists = [];

    public bool $showCreate = false;

    public string $newName = '';

    public ?string $error = null;

    public function navTitle(): string
    {
        return 'Lists';
    }

    public function mount(): void
    {
        $this->load();
    }

    public function refresh(): void
    {
        $this->load();
    }

    public function openList(int $listId): void
    {
        $this->navigate('/lists/'.$listId);
    }

    public function openCreate(): void
    {
        $this->newName = '';
        $this->error = null;
        $this->showCreate = true;
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
    }

    public function createList(): void
    {
        $name = trim($this->newName);

        if ($name === '') {
            $this->error = 'Give the list a name.';

            return;
        }

        try {
            $response = app(ApiClient::class)->post('/shopping-lists', ['name' => $name]);
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = $response->json('errors.name.0') ?? 'The list could not be created.';

            return;
        }

        $this->showCreate = false;
        $this->error = null;
        $this->navigate('/lists/'.$response->json('data.id'));
    }

    protected function load(): void
    {
        try {
            $response = app(ApiClient::class)->get('/shopping-lists');
        } catch (UnauthenticatedException) {
            $this->signOut();

            return;
        }

        if (! $response->successful()) {
            $this->error = 'Your lists could not be loaded. Pull down to try again.';

            return;
        }

        $this->lists = $response->json('data') ?? [];
        $this->error = null;
    }

    protected function signOut(): void
    {
        app(Session::class)->signOut();
        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.lists.shopping-list-index');
    }
}
