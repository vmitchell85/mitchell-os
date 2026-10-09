<?php

namespace App\Actions\Skylight;

use App\Models\Meal;
use App\Services\Mcp\McpException;
use App\Services\Skylight\SkylightClient;
use App\Services\Skylight\SkylightMeal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Two-way sync of approved meals with Skylight's meal plan, from a week
 * ago to four weeks ahead. Drafts never sync.
 *
 * Like shopping items, each meal keeps a snapshot of the state both sides
 * agreed on. Meals are mostly planned here, so when both sides changed the
 * same field this app wins. A meal deleted on one side but edited on the
 * other is kept. Each date of a repeating Skylight meal is its own Meal;
 * changing one splits it off the series into a one-off meal.
 */
class SyncMeals
{
    public const int DaysBack = 7;

    public const int DaysAhead = 28;

    /**
     * Skylight's meal category ids, keyed by lower-case label.
     *
     * @var array<string, string>
     */
    protected array $categoryIds = [];

    public function __construct(protected SkylightClient $skylight) {}

    /**
     * @throws McpException
     */
    public function handle(?SyncReport $report = null): SyncReport
    {
        $report ??= new SyncReport;
        $from = CarbonImmutable::today()->subDays(self::DaysBack);
        $to = CarbonImmutable::today()->addDays(self::DaysAhead);

        foreach ($this->skylight->mealCategories() as $category) {
            $this->categoryIds[strtolower($category->label)] = $category->id;
        }

        /** @var Collection<string, array{SkylightMeal, string}> $occurrences */
        $occurrences = collect();

        foreach ($this->skylight->meals($from, $to) as $remoteMeal) {
            foreach ($remoteMeal->dates as $date) {
                $occurrences->put("{$remoteMeal->id}|{$date}", [$remoteMeal, $date]);
            }
        }

        $meals = Meal::withTrashed()->approved()
            ->where(fn ($query) => $query->whereBetween('date', [$from, $to])->orWhereBetween('skylight_date', [$from, $to]))
            ->get();

        foreach ($meals as $meal) {
            $occurrence = $this->pullOccurrence($occurrences, $meal);

            match (true) {
                $meal->skylight_id === null => $this->pushNewMeal($meal, $report),
                $occurrence === null => $this->resolveDeletedInSkylight($meal, $report),
                $meal->trashed() => $this->resolveDeletedHere($meal, $occurrence, $report),
                default => $this->merge($meal, $occurrence, $report),
            };
        }

        foreach ($occurrences as [$remoteMeal, $date]) {
            $meal = new Meal(['approved_at' => now()]);
            $this->applyState($meal, $this->remoteState($remoteMeal, $date), $remoteMeal->id, $remoteMeal->isRecurring);
            $meal->save();
            $report->pulled++;
        }

        return $report;
    }

    /**
     * Find and remove the Skylight occurrence a meal is linked to. A one-off
     * meal moved to another date in Skylight is still found by its id.
     *
     * @param  Collection<string, array{SkylightMeal, string}>  $occurrences
     * @return array{SkylightMeal, string}|null
     */
    protected function pullOccurrence(Collection $occurrences, Meal $meal): ?array
    {
        if ($meal->skylight_id === null) {
            return null;
        }

        $key = "{$meal->skylight_id}|{$meal->skylight_date?->toDateString()}";

        if ($occurrences->has($key)) {
            return $occurrences->pull($key);
        }

        $movedKey = $occurrences->keys()->first(function (string $key) use ($occurrences, $meal): bool {
            [$remoteMeal] = $occurrences->get($key);

            return $remoteMeal->id === $meal->skylight_id && ! $remoteMeal->isRecurring;
        });

        return $movedKey !== null ? $occurrences->pull($movedKey) : null;
    }

    protected function pushNewMeal(Meal $meal, SyncReport $report): void
    {
        if ($meal->trashed()) {
            $meal->forceDelete();

            return;
        }

        $this->createInSkylight($meal);
        $report->pushed++;
    }

    protected function createInSkylight(Meal $meal): void
    {
        $remoteMeal = $this->skylight->planMeal(
            $this->categoryId($meal->category),
            $meal->date,
            $meal->name,
            $meal->description ?? '',
            $meal->note ?? '',
        );

        $this->applyState($meal, $meal->syncableState(), $remoteMeal->id, isRepeating: false);
        $meal->save();
    }

    protected function resolveDeletedInSkylight(Meal $meal, SyncReport $report): void
    {
        if ($meal->trashed()) {
            $meal->forceDelete();

            return;
        }

        if ($meal->syncableState() !== $meal->skylight_snapshot) {
            $this->createInSkylight($meal);
            $report->kept++;

            return;
        }

        $meal->forceDelete();
        $report->removedHere++;
    }

    /**
     * @param  array{SkylightMeal, string}  $occurrence
     */
    protected function resolveDeletedHere(Meal $meal, array $occurrence, SyncReport $report): void
    {
        [$remoteMeal, $date] = $occurrence;

        if ($this->remoteState($remoteMeal, $date) !== $meal->skylight_snapshot) {
            $meal->restore();
            $this->merge($meal, $occurrence, $report);
            $report->kept++;

            return;
        }

        $this->skylight->deleteMeal($remoteMeal->id, CarbonImmutable::parse($date));
        $meal->forceDelete();
        $report->removedFromSkylight++;
    }

    /**
     * @param  array{SkylightMeal, string}  $occurrence
     */
    protected function merge(Meal $meal, array $occurrence, SyncReport $report): void
    {
        [$remoteMeal, $date] = $occurrence;
        $remote = $this->remoteState($remoteMeal, $date);
        $snapshot = $meal->skylight_snapshot ?? $remote;
        $merged = $meal->syncableState();
        $changedHere = false;

        foreach (array_keys($merged) as $field) {
            if ($merged[$field] !== $snapshot[$field]) {
                $changedHere = $changedHere || $merged[$field] !== $remote[$field];
            } elseif ($remote[$field] !== $snapshot[$field]) {
                $merged[$field] = $remote[$field];
            }
        }

        $pulledChanges = $merged !== $meal->syncableState();
        $this->applyState($meal, $merged, $remoteMeal->id, $remoteMeal->isRecurring, skylightDate: $date);

        if ($changedHere) {
            $this->pushChanges($meal, $remoteMeal, $date, $remote, $merged);
            $report->pushed++;
        }

        if ($pulledChanges) {
            $report->pulled++;
        }

        $meal->save();
    }

    /**
     * @param  array{date: string, category: string, name: string, description: string, note: string}  $remote
     * @param  array{date: string, category: string, name: string, description: string, note: string}  $merged
     */
    protected function pushChanges(Meal $meal, SkylightMeal $remoteMeal, string $date, array $remote, array $merged): void
    {
        if ($remoteMeal->isRecurring) {
            $this->skylight->deleteMeal($remoteMeal->id, CarbonImmutable::parse($date));
            $this->createInSkylight($meal);

            return;
        }

        $changes = [];

        foreach (['name' => 'name', 'description' => 'description', 'note' => 'note'] as $field => $key) {
            if ($merged[$field] !== $remote[$field]) {
                $changes[$key] = $merged[$field];
            }
        }

        if ($merged['category'] !== $remote['category']) {
            $changes['categoryId'] = $this->categoryId($merged['category']);
        }

        if ($merged['date'] !== $remote['date']) {
            $changes['date'] = CarbonImmutable::parse($merged['date']);
        }

        $this->skylight->updateMeal($remoteMeal->id, CarbonImmutable::parse($date), $changes);
        $meal->skylight_date = Carbon::parse($merged['date']);
    }

    /**
     * @param  array{date: string, category: string, name: string, description: string, note: string}  $state
     */
    protected function applyState(Meal $meal, array $state, string $skylightId, bool $isRepeating, ?string $skylightDate = null): void
    {
        $meal->fill([
            'date' => $state['date'],
            'category' => $state['category'],
            'name' => $state['name'],
            'description' => $state['description'] ?: null,
            'note' => $state['note'] ?: null,
            'skylight_id' => $skylightId,
            'skylight_date' => $skylightDate ?? $state['date'],
            'repeats_in_skylight' => $isRepeating,
            'skylight_snapshot' => $state,
        ]);
    }

    /**
     * @return array{date: string, category: string, name: string, description: string, note: string}
     */
    protected function remoteState(SkylightMeal $remoteMeal, string $date): array
    {
        return [
            'date' => $date,
            'category' => $remoteMeal->category->label ?? 'Dinner',
            'name' => $remoteMeal->name,
            'description' => $remoteMeal->description,
            'note' => $remoteMeal->note,
        ];
    }

    /**
     * @throws McpException
     */
    protected function categoryId(string $category): string
    {
        return $this->categoryIds[strtolower($category)]
            ?? throw new McpException("Skylight has no [{$category}] meal category.");
    }
}
