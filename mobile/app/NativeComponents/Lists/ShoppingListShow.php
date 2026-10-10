<?php

namespace App\NativeComponents\Lists;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class ShoppingListShow extends NativeComponent
{
    public const string DisplayTimezone = 'America/New_York';

    public int $listId = 0;

    public string $listName = 'List';

    /** @var list<array{id: int, name: string, section: string|null, completed: bool}> */
    public array $items = [];

    public string $newItem = '';

    public ?string $error = null;

    public bool $showBuilder = false;

    public string $from = '';

    public string $to = '';

    /** @var list<array{name: string, section: string|null, meals: list<string>}> */
    public array $proposals = [];

    /** @var list<int> */
    public array $selectedProposals = [];

    public bool $hasProposals = false;

    public function navTitle(): string
    {
        return $this->listName;
    }

    public function mount(): void
    {
        $this->listId = (int) $this->param('id');
        $this->load();
    }

    public function refresh(): void
    {
        $this->load();
    }

    public function toggleItem(int $itemId, ?bool $completed = null): void
    {
        $index = $this->itemIndex($itemId);

        if ($index === null) {
            return;
        }

        $was = $this->items[$index]['completed'];
        $completed ??= ! $was;
        $this->items[$index]['completed'] = $completed;

        $response = $this->send(fn (ApiClient $api) => $api->patch("/shopping-lists/{$this->listId}/items/{$itemId}", ['completed' => $completed]));

        if ($response !== null && ! $response->successful()) {
            $this->items[$index]['completed'] = $was;
            $this->error = 'That item could not be updated.';
        }
    }

    public function deleteItem(int $itemId): void
    {
        $index = $this->itemIndex($itemId);

        if ($index === null) {
            return;
        }

        $removed = $this->items[$index];
        array_splice($this->items, $index, 1);

        $response = $this->send(fn (ApiClient $api) => $api->delete("/shopping-lists/{$this->listId}/items/{$itemId}"));

        if ($response !== null && ! $response->successful()) {
            array_splice($this->items, $index, 0, [$removed]);
            $this->error = 'That item could not be removed.';
        }
    }

    public function addItem(): void
    {
        $name = trim($this->newItem);

        if ($name === '') {
            return;
        }

        $response = $this->send(fn (ApiClient $api) => $api->post("/shopping-lists/{$this->listId}/items", ['name' => $name]));

        if ($response === null) {
            return;
        }

        if (! $response->successful()) {
            $this->error = $response->json('errors.name.0') ?? 'That item could not be added.';

            return;
        }

        $this->items[] = $this->itemFrom($response->json('data'));
        $this->newItem = '';
        $this->error = null;
    }

    public function openBuilder(): void
    {
        $today = Carbon::now(self::DisplayTimezone);

        $this->from = $today->toDateString();
        $this->to = $today->addDays(6)->toDateString();
        $this->proposals = [];
        $this->selectedProposals = [];
        $this->hasProposals = false;
        $this->error = null;
        $this->showBuilder = true;
    }

    public function closeBuilder(): void
    {
        $this->showBuilder = false;
    }

    public function buildFromMeals(): void
    {
        $this->error = null;

        $response = $this->send(fn (ApiClient $api) => $api->post("/shopping-lists/{$this->listId}/proposals", ['from' => $this->from, 'to' => $this->to]));

        if ($response === null) {
            return;
        }

        if (! $response->successful()) {
            $this->error = $response->json('errors.to.0') ?? $response->json('errors.from.0') ?? 'The meals could not be turned into a list. Try again.';

            return;
        }

        $this->proposals = $response->json('data') ?? [];
        $this->selectedProposals = array_keys($this->proposals);
        $this->hasProposals = true;
    }

    public function toggleProposal(int $index, ?bool $selected = null): void
    {
        $isSelected = in_array($index, $this->selectedProposals, true);
        $selected ??= ! $isSelected;

        $without = array_values(array_diff($this->selectedProposals, [$index]));
        $this->selectedProposals = $selected ? [...$without, $index] : $without;
    }

    public function addSelectedProposals(): void
    {
        $failed = [];

        foreach ($this->proposals as $index => $proposal) {
            if (! in_array($index, $this->selectedProposals, true)) {
                continue;
            }

            $response = $this->send(fn (ApiClient $api) => $api->post("/shopping-lists/{$this->listId}/items", array_filter([
                'name' => $proposal['name'],
                'section' => $proposal['section'] ?? null,
            ])));

            if ($response === null) {
                return;
            }

            if ($response->successful()) {
                $this->items[] = $this->itemFrom($response->json('data'));
            } else {
                $failed[] = $index;
            }
        }

        if ($failed !== []) {
            $this->selectedProposals = $failed;
            $this->error = 'Some items could not be added. They are still selected.';

            return;
        }

        $this->showBuilder = false;
        $this->hasProposals = false;
        $this->proposals = [];
        $this->selectedProposals = [];
    }

    protected function load(): void
    {
        $response = $this->send(fn (ApiClient $api) => $api->get("/shopping-lists/{$this->listId}"));

        if ($response === null) {
            return;
        }

        if (! $response->successful()) {
            $this->error = 'This list could not be loaded. Pull down to try again.';

            return;
        }

        $this->listName = $response->json('data.name') ?? 'List';
        $this->items = array_map($this->itemFrom(...), $response->json('data.items') ?? []);
        $this->error = null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: int, name: string, section: string|null, completed: bool}
     */
    protected function itemFrom(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'name' => (string) $item['name'],
            'section' => $item['section'] ?? null,
            'completed' => (bool) ($item['completed'] ?? false),
        ];
    }

    protected function itemIndex(int $itemId): ?int
    {
        foreach ($this->items as $index => $item) {
            if ($item['id'] === $itemId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Runs an API call; returns null (after sending the user to sign in) when the token was rejected.
     *
     * @param  callable(ApiClient): Response  $call
     */
    protected function send(callable $call): ?Response
    {
        try {
            return $call(app(ApiClient::class));
        } catch (UnauthenticatedException) {
            app(Session::class)->signOut();
            $this->replace('/sign-in');

            return null;
        }
    }

    public function render(): View
    {
        return view('native.lists.shopping-list-show');
    }
}
