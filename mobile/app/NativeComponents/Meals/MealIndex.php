<?php

namespace App\NativeComponents\Meals;

use App\Services\Api\ApiClient;
use App\Services\Api\Session;
use App\Services\Api\UnauthenticatedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\NativeComponent;

/**
 * A week of meals grouped by day, where drafts can be reviewed and Claude can plan the week.
 */
class MealIndex extends NativeComponent
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $meals = [];

    public int $weekOffset = 0;

    public bool $loaded = false;

    public bool $planning = false;

    public bool $planRequested = false;

    public string $error = '';

    public string $status = '';

    public function mount(ApiClient $api, Session $session): void
    {
        $this->loadMeals($api, $session);
    }

    public function navTitle(): string
    {
        return 'Meals';
    }

    public function refresh(ApiClient $api, Session $session): void
    {
        $this->loadMeals($api, $session);
    }

    public function previousWeek(ApiClient $api, Session $session): void
    {
        $this->weekOffset--;
        $this->loadMeals($api, $session);
    }

    public function nextWeek(ApiClient $api, Session $session): void
    {
        $this->weekOffset++;
        $this->loadMeals($api, $session);
    }

    public function thisWeek(ApiClient $api, Session $session): void
    {
        $this->weekOffset = 0;
        $this->loadMeals($api, $session);
    }

    /**
     * Claude takes a while to draft a week, so this only flags the request; the
     * screen repaints with a progress message before continuePlanning() blocks.
     */
    public function planWeek(): void
    {
        if ($this->planning) {
            return;
        }

        $this->error = '';
        $this->status = '';
        $this->planning = true;
        $this->planRequested = true;
    }

    #[Poll(1500)]
    public function continuePlanning(ApiClient $api, Session $session): void
    {
        if (! $this->planRequested) {
            return;
        }

        $this->planRequested = false;

        try {
            $response = $api->post('/meal-plans', ['week' => $this->weekStart()->toDateString()]);
        } catch (UnauthenticatedException) {
            $this->signedOut($session);

            return;
        } catch (ConnectionException) {
            $this->planning = false;
            $this->status = 'Claude is still working on it. Pull down to refresh in a minute.';

            return;
        }

        $this->planning = false;

        if (! $response->successful()) {
            $this->error = (string) ($response->json('message') ?? 'Claude could not plan the week. Try again in a moment.');

            return;
        }

        $count = count($response->json('data') ?? []);
        $this->status = $count > 0
            ? "Claude suggested {$count} ".($count === 1 ? 'meal' : 'meals').'. Review and approve them below.'
            : 'Every slot this week is already filled.';

        $this->loadMeals($api, $session, keepStatus: true);
    }

    /**
     * @return list<array{label: string, meals: list<array<string, mixed>>}>
     */
    #[Computed]
    public function days(): array
    {
        $days = [];

        foreach ($this->meals as $meal) {
            $days[$meal['date']]['label'] ??= CarbonImmutable::parse($meal['date'])->format('l, F j');
            $days[$meal['date']]['meals'][] = $meal;
        }

        return array_values($days);
    }

    #[Computed]
    public function weekLabel(): string
    {
        $start = $this->weekStart();

        return $start->format('M j').' – '.$start->addDays(6)->format('M j');
    }

    #[Computed]
    public function draftCount(): int
    {
        return count(array_filter($this->meals, fn (array $meal): bool => (bool) ($meal['is_draft'] ?? false)));
    }

    public function render(): View
    {
        return view('native.meals.meal-index');
    }

    protected function weekStart(): CarbonImmutable
    {
        return CarbonImmutable::now('America/New_York')
            ->startOfWeek()
            ->addWeeks($this->weekOffset);
    }

    protected function loadMeals(ApiClient $api, Session $session, bool $keepStatus = false): void
    {
        $this->error = '';

        if (! $keepStatus) {
            $this->status = '';
        }

        $start = $this->weekStart();

        try {
            $response = $api->get('/meals', ['from' => $start->toDateString(), 'to' => $start->addDays(6)->toDateString()]);
        } catch (UnauthenticatedException) {
            $this->signedOut($session);

            return;
        } catch (ConnectionException) {
            $this->loaded = true;
            $this->error = 'Could not reach the server. Check that you are on the home network.';

            return;
        }

        $this->loaded = true;

        if (! $response->successful()) {
            $this->error = 'Could not load the meals. Pull down to try again.';

            return;
        }

        $this->meals = $response->json('data') ?? [];
    }

    protected function signedOut(Session $session): void
    {
        $session->signOut();
        $this->replace('/sign-in');
    }
}
