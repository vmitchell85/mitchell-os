<?php

namespace App\NativeComponents\Meals;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Dialog;

/**
 * One meal: approve or regenerate a draft, edit it, or take it off the plan.
 */
class MealShow extends NativeComponent
{
    public const array Categories = ['Breakfast', 'Lunch', 'Dinner'];

    /**
     * @var array<string, mixed>
     */
    public array $meal = [];

    public bool $loaded = false;

    public bool $editing = false;

    public bool $busy = false;

    public bool $regenerateRequested = false;

    public string $name = '';

    public string $category = 'Dinner';

    public string $date = '';

    public string $ingredients = '';

    public string $note = '';

    public string $regenerateNotes = '';

    public string $error = '';

    public string $status = '';

    public function mount(ApiClient $api, Session $session): void
    {
        try {
            $response = $api->get('/meals/'.$this->param('id'));
        } catch (UnauthenticatedException) {
            $this->signedOut($session);

            return;
        } catch (ConnectionException) {
            $this->error = 'Could not reach the server. Check that you are on the home network.';

            return;
        }

        $this->loaded = true;

        if (! $response->successful()) {
            $this->error = $response->status() === 404 ? 'This meal is no longer on the plan.' : 'Could not load this meal.';

            return;
        }

        $this->fill($response->json('data'));
    }

    public function navTitle(): string
    {
        return 'Meal';
    }

    public function approve(ApiClient $api, Session $session): void
    {
        $this->send(fn () => $api->post("/meals/{$this->meal['id']}/approve"), $session, 'Approved. It will reach Skylight at the next sync.');
    }

    /**
     * Claude takes a while to suggest a replacement, so this only flags the
     * request; continueRegenerating() does the blocking call after a repaint.
     */
    public function regenerate(): void
    {
        if ($this->busy || ! ($this->meal['is_draft'] ?? false)) {
            return;
        }

        $this->error = '';
        $this->status = '';
        $this->busy = true;
        $this->regenerateRequested = true;
    }

    #[Poll(1500)]
    public function continueRegenerating(ApiClient $api, Session $session): void
    {
        if (! $this->regenerateRequested) {
            return;
        }

        $this->regenerateRequested = false;

        $notes = trim($this->regenerateNotes);

        $this->send(
            fn () => $api->post("/meals/{$this->meal['id']}/regenerate", $notes !== '' ? ['notes' => $notes] : []),
            $session,
            'Claude suggested something different.',
        );

        $this->regenerateNotes = '';
    }

    public function startEditing(): void
    {
        $this->editing = true;
        $this->status = '';
        $this->error = '';
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->error = '';
        $this->fill($this->meal);
    }

    public function save(ApiClient $api, Session $session): void
    {
        $ingredients = array_values(array_filter(array_map('trim', explode("\n", $this->ingredients))));

        $this->send(fn () => $api->put("/meals/{$this->meal['id']}", [
            'name' => trim($this->name),
            'category' => $this->category,
            'date' => trim($this->date),
            'ingredients' => $ingredients,
            'note' => trim($this->note),
        ]), $session, 'Saved.');
    }

    public function confirmDelete(): void
    {
        Dialog::alert('Remove this meal?', 'It comes off the plan and, if approved, out of Skylight at the next sync.', [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Remove', 'style' => 'destructive'],
        ])->buttonPressed(function ($event): void {
            if ($event->label === 'Remove') {
                $this->delete(app(ApiClient::class), app(Session::class));
            }
        });
    }

    public function delete(ApiClient $api, Session $session): void
    {
        $this->busy = true;

        try {
            $response = $api->delete("/meals/{$this->meal['id']}");
        } catch (UnauthenticatedException) {
            $this->signedOut($session);

            return;
        } catch (ConnectionException) {
            $this->busy = false;
            $this->error = 'Could not reach the server. Try again.';

            return;
        }

        $this->busy = false;

        if (! $response->successful()) {
            $this->error = 'Could not remove this meal.';

            return;
        }

        $this->back();
    }

    public function render(): View
    {
        return view('native.meals.meal-show', [
            'dateLabel' => $this->meal === [] ? '' : CarbonImmutable::parse($this->meal['date'])->format('l, F j'),
            'approvedLabel' => $this->approvedLabel(),
            'ingredientList' => $this->meal['ingredients'] ?? [],
            'categories' => self::Categories,
        ]);
    }

    /**
     * Runs a request that returns the updated meal and shows the result.
     *
     * @param  callable(): Response  $request
     */
    protected function send(callable $request, Session $session, string $successMessage): void
    {
        $this->busy = true;
        $this->error = '';
        $this->status = '';

        try {
            $response = $request();
        } catch (UnauthenticatedException) {
            $this->signedOut($session);

            return;
        } catch (ConnectionException) {
            $this->busy = false;
            $this->error = 'Could not reach the server, or it took too long. Pull back and check the meal before trying again.';

            return;
        }

        $this->busy = false;

        if ($response->successful()) {
            $this->fill($response->json('data'));
            $this->editing = false;
            $this->status = $successMessage;

            return;
        }

        $this->error = (string) ($response->json('message') ?? 'Something went wrong. Try again.');
    }

    /**
     * @param  array<string, mixed>  $meal
     */
    protected function fill(array $meal): void
    {
        $this->meal = $meal;
        $this->name = (string) ($meal['name'] ?? '');
        $this->category = (string) ($meal['category'] ?? 'Dinner');
        $this->date = (string) ($meal['date'] ?? '');
        $this->ingredients = implode("\n", $meal['ingredients'] ?? []);
        $this->note = (string) ($meal['note'] ?? '');
    }

    protected function approvedLabel(): string
    {
        if (empty($this->meal['approved_at'])) {
            return '';
        }

        return CarbonImmutable::parse($this->meal['approved_at'])->setTimezone('America/New_York')->format('F j \a\t g:i A');
    }

    protected function signedOut(Session $session): void
    {
        $session->signOut();
        $this->replace('/sign-in');
    }
}
