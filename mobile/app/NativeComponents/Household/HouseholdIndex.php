<?php

namespace App\NativeComponents\Household;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Illuminate\Http\Client\Response;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

/**
 * The household's food rules and the notes Claude always keeps in mind.
 */
class HouseholdIndex extends NativeComponent
{
    public string $foodRules = '';

    public string $savedFoodRules = '';

    /** @var array<int, array{id: int, body: string}> */
    public array $notes = [];

    public string $newNote = '';

    public ?int $editingNoteId = null;

    public string $editingBody = '';

    public bool $loaded = false;

    public bool $savingFoodRules = false;

    public string $error = '';

    public string $status = '';

    public function navTitle(): string
    {
        return 'Household';
    }

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->error = '';

        $rules = $this->request(fn (ApiClient $api): Response => $api->get('/food-rules'));
        $notes = $this->request(fn (ApiClient $api): Response => $api->get('/notes'));

        if ($rules?->successful()) {
            $this->savedFoodRules = (string) $rules->json('data.food_rules');
            $this->foodRules = $this->savedFoodRules;
        }

        if ($notes?->successful()) {
            $this->notes = $this->noteRows($notes);
        }

        $this->loaded = true;

        if (! $rules?->successful() || ! $notes?->successful()) {
            $this->error = 'Could not load the household. Check your connection and try again.';
        }
    }

    public function saveFoodRules(): void
    {
        $this->error = '';
        $this->status = '';
        $this->savingFoodRules = true;

        $response = $this->request(fn (ApiClient $api): Response => $api->put('/food-rules', [
            'food_rules' => $this->foodRules,
        ]));

        $this->savingFoodRules = false;

        if ($response?->successful()) {
            $this->savedFoodRules = (string) $response->json('data.food_rules');
            $this->foodRules = $this->savedFoodRules;
            $this->status = 'Food rules saved.';

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not save the food rules.');
    }

    public function addNote(): void
    {
        $this->error = '';
        $this->status = '';

        if (trim($this->newNote) === '') {
            return;
        }

        $response = $this->request(fn (ApiClient $api): Response => $api->post('/notes', ['body' => $this->newNote]));

        if ($response?->successful()) {
            $this->notes[] = $this->noteRow($response->json('data'));
            $this->newNote = '';

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not add the note.');
    }

    public function editNote(int $id): void
    {
        foreach ($this->notes as $note) {
            if ($note['id'] === $id) {
                $this->editingNoteId = $id;
                $this->editingBody = $note['body'];
                $this->error = '';
                $this->status = '';

                return;
            }
        }
    }

    public function cancelEdit(): void
    {
        $this->editingNoteId = null;
        $this->editingBody = '';
    }

    public function saveNote(): void
    {
        if ($this->editingNoteId === null) {
            return;
        }

        $this->error = '';

        if (trim($this->editingBody) === '') {
            $this->error = 'A note cannot be empty.';

            return;
        }

        $id = $this->editingNoteId;
        $response = $this->request(fn (ApiClient $api): Response => $api->put("/notes/{$id}", ['body' => $this->editingBody]));

        if ($response?->successful()) {
            $updated = $this->noteRow($response->json('data'));
            $this->notes = array_map(fn (array $note): array => $note['id'] === $id ? $updated : $note, $this->notes);
            $this->cancelEdit();

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not save the note.');
    }

    public function deleteNote(int $id): void
    {
        $this->error = '';
        $this->status = '';

        $response = $this->request(fn (ApiClient $api): Response => $api->delete("/notes/{$id}"));

        if ($response?->successful()) {
            $this->notes = array_values(array_filter($this->notes, fn (array $note): bool => $note['id'] !== $id));

            if ($this->editingNoteId === $id) {
                $this->cancelEdit();
            }

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not delete the note.');
    }

    public function render(): View
    {
        return view('native.household.household-index');
    }

    /**
     * Runs an API call, sending the user to sign in if the token was rejected.
     * Returns null when the server cannot be reached.
     *
     * @param  callable(ApiClient): Response  $call
     */
    protected function request(callable $call): ?Response
    {
        try {
            return $call(app(ApiClient::class));
        } catch (UnauthenticatedException) {
            app(Session::class)->signOut();
            $this->replace('/sign-in');
        } catch (Throwable) {
            // Connection failures fall through to the caller's error message.
        }

        return null;
    }

    protected function errorMessage(?Response $response, string $fallback): string
    {
        if ($response === null) {
            return 'Could not reach the server. Check your connection and try again.';
        }

        $first = collect($response->json('errors', []))->flatten()->first();

        return is_string($first) ? $first : $fallback;
    }

    /**
     * @return array<int, array{id: int, body: string}>
     */
    protected function noteRows(Response $response): array
    {
        return array_map(fn (array $note): array => $this->noteRow($note), $response->json('data', []));
    }

    /**
     * @param  array<string, mixed>  $note
     * @return array{id: int, body: string}
     */
    protected function noteRow(array $note): array
    {
        return ['id' => (int) $note['id'], 'body' => (string) $note['body']];
    }
}
