<?php

use App\Actions\Meals\BuildShoppingList;
use App\Actions\Meals\DraftMealPlan;
use App\Actions\Meals\RegenerateMeal;
use App\Actions\Skylight\SyncWithSkylight;
use App\Models\Meal;
use App\Models\ShoppingList;
use App\Services\Claude\ClaudeException;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Meals')] class extends Component {
    /**
     * The Monday of the week shown, as YYYY-MM-DD.
     */
    #[Url]
    public string $week = '';

    public string $notes = '';

    /**
     * @var list<string>
     */
    public array $planCategories = ['Dinner'];

    #[Locked]
    public ?int $editingMealId = null;

    public string $mealDate = '';

    public string $mealCategory = 'Dinner';

    public string $mealName = '';

    public string $mealDescription = '';

    public string $mealNote = '';

    public ?int $shoppingListId = null;

    public string $shopFrom = '';

    public string $shopTo = '';

    /**
     * What Claude suggests buying, awaiting review.
     *
     * @var list<array{name: string, section: string|null, meals: list<string>}>
     */
    #[Locked]
    public array $proposals = [];

    /**
     * Indexes of the proposals to add.
     *
     * @var list<int|string>
     */
    public array $selectedProposals = [];

    /**
     * Mount the component, showing this week unless a valid week is given.
     */
    public function mount(): void
    {
        try {
            $this->week = CarbonImmutable::parse($this->week ?: 'today')->startOfWeek()->toDateString();
        } catch (Exception) {
            $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
        }
    }

    public function previousWeek(): void
    {
        $this->week = $this->weekStart()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->weekStart()->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = CarbonImmutable::today()->startOfWeek()->toDateString();
    }

    public function sync(SyncWithSkylight $sync): void
    {
        $outcome = $sync->attempt();

        Flux::toast(variant: $outcome->variant, heading: $outcome->heading, text: $outcome->message);
    }

    public function plan(DraftMealPlan $draftMealPlan): void
    {
        $validated = $this->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'planCategories' => ['required', 'array', 'min:1'],
            'planCategories.*' => [Rule::in(Meal::Categories)],
        ]);

        try {
            $drafts = $draftMealPlan->handle(Auth::user(), $this->weekStart(), $validated['planCategories'], $validated['notes'] ?? '');
        } catch (ClaudeException $exception) {
            report($exception);
            Flux::toast(variant: 'danger', heading: __('Claude couldn\'t plan the week'), text: $exception->getMessage());

            return;
        }

        Flux::modal('plan-week')->close();

        Flux::toast(
            variant: $drafts->isEmpty() ? 'warning' : 'success',
            text: $drafts->isEmpty()
                ? __('Every slot you asked for is already planned.')
                : trans_choice(':count meal suggested. Review it below.|:count meals suggested. Review them below.', $drafts->count()),
        );
    }

    public function regenerate(int $mealId, RegenerateMeal $regenerateMeal): void
    {
        try {
            $regenerateMeal->handle(Auth::user(), $this->findDraft($mealId), $this->notes);
        } catch (ClaudeException $exception) {
            report($exception);
            Flux::toast(variant: 'danger', heading: __('Claude couldn\'t suggest another meal'), text: $exception->getMessage());
        }
    }

    public function approve(int $mealId): void
    {
        $this->findDraft($mealId)->update(['approved_at' => now()]);
    }

    public function approveAll(): void
    {
        $this->weekMeals()->drafts()->update(['approved_at' => now()]);
    }

    /**
     * Drafts never reached Skylight, so they're deleted outright.
     */
    public function discardDrafts(): void
    {
        $this->weekMeals()->drafts()->forceDelete();
    }

    /**
     * Approved meals are soft-deleted so the next sync removes them from Skylight too.
     */
    public function remove(int $mealId): void
    {
        $meal = Meal::findOrFail($mealId);

        $meal->isDraft() ? $meal->forceDelete() : $meal->delete();
    }

    public function newMeal(string $date): void
    {
        $this->reset('editingMealId', 'mealName', 'mealDescription', 'mealNote');
        $this->mealDate = CarbonImmutable::parse($date)->toDateString();
        $this->mealCategory = 'Dinner';
        $this->resetValidation();

        Flux::modal('meal-form')->show();
    }

    public function edit(int $mealId): void
    {
        $meal = Meal::findOrFail($mealId);

        $this->editingMealId = $meal->id;
        $this->mealDate = $meal->date->toDateString();
        $this->mealCategory = $meal->category;
        $this->mealName = $meal->name;
        $this->mealDescription = $meal->description ?? '';
        $this->mealNote = $meal->note ?? '';
        $this->resetValidation();

        Flux::modal('meal-form')->show();
    }

    /**
     * Meals added by hand are approved straight away.
     */
    public function saveMeal(): void
    {
        $validated = $this->validate([
            'mealDate' => ['required', 'date'],
            'mealCategory' => ['required', Rule::in(Meal::Categories)],
            'mealName' => ['required', 'string', 'max:255'],
            'mealDescription' => ['nullable', 'string', 'max:5000'],
            'mealNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $attributes = [
            'date' => $validated['mealDate'],
            'category' => $validated['mealCategory'],
            'name' => $validated['mealName'],
            'description' => $validated['mealDescription'] ?: null,
            'note' => $validated['mealNote'] ?: null,
        ];

        if ($this->editingMealId !== null) {
            Meal::findOrFail($this->editingMealId)->update($attributes);
        } else {
            Meal::create([...$attributes, 'approved_at' => now(), 'added_by' => Auth::id()]);
        }

        Flux::modal('meal-form')->close();
    }

    public function openShoppingList(): void
    {
        $this->reset('proposals', 'selectedProposals');
        $this->shoppingListId = ShoppingList::orderByDesc('is_default')->orderBy('name')->value('id');
        $this->shopFrom = $this->weekStart()->toDateString();
        $this->shopTo = $this->weekStart()->addDays(6)->toDateString();
        $this->resetValidation();

        Flux::modal('shopping-list')->show();
    }

    public function buildShoppingList(BuildShoppingList $buildShoppingList): void
    {
        $validated = $this->validate([
            'shoppingListId' => ['required', 'integer', Rule::exists('shopping_lists', 'id')],
            'shopFrom' => ['required', 'date'],
            'shopTo' => ['required', 'date', 'after_or_equal:shopFrom'],
        ]);

        try {
            $this->proposals = $buildShoppingList->handle(
                Auth::user(),
                ShoppingList::findOrFail($validated['shoppingListId']),
                CarbonImmutable::parse($validated['shopFrom']),
                CarbonImmutable::parse($validated['shopTo']),
            );
        } catch (ClaudeException $exception) {
            report($exception);
            Flux::toast(variant: 'danger', heading: __('Claude couldn\'t build the list'), text: $exception->getMessage());

            return;
        }

        $this->selectedProposals = array_keys($this->proposals);

        if ($this->proposals === []) {
            Flux::toast(variant: 'warning', text: __('Nothing to add: there are no approved meals in those dates, or everything is already on the list.'));
        }
    }

    public function startOver(): void
    {
        $this->reset('proposals', 'selectedProposals');
    }

    public function addProposals(): void
    {
        $list = ShoppingList::findOrFail($this->shoppingListId);
        $position = (int) $list->items()->max('position');
        $added = 0;

        foreach ($this->selectedProposals as $index) {
            $proposal = $this->proposals[(int) $index] ?? null;

            if ($proposal === null) {
                continue;
            }

            $list->items()->create([
                'name' => $proposal['name'],
                'section' => $proposal['section'],
                'position' => ++$position,
                'added_by' => Auth::id(),
            ]);
            $added++;
        }

        $this->reset('proposals', 'selectedProposals');
        Flux::modal('shopping-list')->close();

        Flux::toast(
            variant: 'success',
            text: trans_choice('Added :count item to :list.|Added :count items to :list.', $added, ['list' => $list->name]),
            link: route('lists', $list),
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ShoppingList>
     */
    #[Computed]
    public function shoppingLists(): \Illuminate\Database\Eloquent\Collection
    {
        return ShoppingList::orderByDesc('is_default')->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Proposals grouped by section, keeping their indexes for selection.
     *
     * @return Collection<string, Collection<int, array{name: string, section: string|null, meals: list<string>}>>
     */
    #[Computed]
    public function proposalsBySection(): Collection
    {
        return collect($this->proposals)->groupBy(fn (array $proposal): string => $proposal['section'] ?? __('Other'), preserveKeys: true)->sortKeys();
    }

    /**
     * The week's days, each with its meals in breakfast, lunch, dinner order.
     *
     * @return Collection<string, Collection<int, Meal>>
     */
    #[Computed]
    public function days(): Collection
    {
        $meals = $this->weekMeals()->get()->groupBy(fn (Meal $meal): string => $meal->date->toDateString());

        return collect(range(0, 6))->mapWithKeys(function (int $offset) use ($meals): array {
            $date = $this->weekStart()->addDays($offset)->toDateString();

            return [$date => ($meals[$date] ?? collect())->sortBy(fn (Meal $meal): int => (int) array_search($meal->category, Meal::Categories, true))->values()];
        });
    }

    #[Computed]
    public function draftCount(): int
    {
        return $this->weekMeals()->drafts()->count();
    }

    protected function weekStart(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->week)->startOfWeek();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Meal>
     */
    protected function weekMeals(): \Illuminate\Database\Eloquent\Builder
    {
        return Meal::whereBetween('date', [$this->weekStart(), $this->weekStart()->addDays(6)]);
    }

    protected function findDraft(int $mealId): Meal
    {
        return Meal::drafts()->findOrFail($mealId);
    }
}; ?>

<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousWeek" :aria-label="__('Previous week')" />
            <flux:heading size="lg">
                {{ CarbonImmutable::parse($week)->format('j M') }} – {{ CarbonImmutable::parse($week)->addDays(6)->format('j M Y') }}
            </flux:heading>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextWeek" :aria-label="__('Next week')" />
            <flux:button size="sm" variant="ghost" wire:click="thisWeek">{{ __('This week') }}</flux:button>
        </div>

        <div class="flex items-center gap-2">
            <flux:button size="sm" icon="shopping-cart" wire:click="openShoppingList">{{ __('Shopping list') }}</flux:button>

            <flux:modal.trigger name="plan-week">
                <flux:button size="sm" variant="primary" icon="sparkles">{{ __('Plan with Claude') }}</flux:button>
            </flux:modal.trigger>

            <flux:button size="sm" icon="arrow-path" wire:click="sync" wire:loading.attr="disabled" wire:target="sync">
                <span wire:loading.remove wire:target="sync">{{ __('Sync') }}</span>
                <span wire:loading wire:target="sync">{{ __('Syncing…') }}</span>
            </flux:button>
        </div>
    </div>

    @if ($this->draftCount > 0)
        <flux:callout icon="sparkles" color="amber">
            <flux:callout.heading>{{ trans_choice(':count suggestion to review|:count suggestions to review', $this->draftCount) }}</flux:callout.heading>
            <flux:callout.text>{{ __('Suggestions only go to Skylight once you approve them.') }}</flux:callout.text>

            <x-slot name="actions">
                <flux:button size="sm" variant="primary" wire:click="approveAll">{{ __('Approve all') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="discardDrafts">{{ __('Discard all') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-5">
        @foreach ($this->days as $date => $meals)
            @php($day = CarbonImmutable::parse($date))

            <section wire:key="day-{{ $date }}" class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <flux:heading @class(['text-accent-content' => $day->isToday()])>
                        {{ $day->format('l j F') }}
                        @if ($day->isToday())
                            <flux:badge size="sm" class="ms-2">{{ __('Today') }}</flux:badge>
                        @endif
                    </flux:heading>

                    <flux:button size="xs" variant="ghost" icon="plus" wire:click="newMeal('{{ $date }}')">{{ __('Add meal') }}</flux:button>
                </div>

                @foreach ($meals as $meal)
                    <div
                        wire:key="meal-{{ $meal->id }}"
                        @class([
                            'flex flex-col gap-2 rounded-xl border p-3 sm:flex-row sm:items-start sm:justify-between',
                            'border-dashed border-amber-400 bg-amber-50/50 dark:border-amber-500/60 dark:bg-amber-500/5' => $meal->isDraft(),
                            'border-zinc-200 dark:border-zinc-700' => ! $meal->isDraft(),
                        ])
                    >
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $meal->name }}</span>
                                <flux:badge size="sm">{{ $meal->category }}</flux:badge>
                                @if ($meal->isDraft())
                                    <flux:badge size="sm" color="amber">{{ __('Suggestion') }}</flux:badge>
                                @endif
                                @if ($meal->repeats_in_skylight)
                                    <flux:badge size="sm" icon="arrow-path-rounded-square">{{ __('Repeats in Skylight') }}</flux:badge>
                                @endif
                            </div>

                            @if ($meal->note)
                                <flux:text size="sm">{{ $meal->note }}</flux:text>
                            @endif

                            @if ($meal->description)
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-zinc-500">{{ __('Ingredients') }}</summary>
                                    <p class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ $meal->description }}</p>
                                </details>
                            @endif
                        </div>

                        <div class="flex shrink-0 gap-1">
                            @if ($meal->isDraft())
                                <flux:button size="xs" variant="primary" wire:click="approve({{ $meal->id }})">{{ __('Approve') }}</flux:button>
                                <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="regenerate({{ $meal->id }})" wire:loading.attr="disabled" wire:target="regenerate({{ $meal->id }})" :aria-label="__('Suggest something else')" />
                            @endif
                            <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $meal->id }})" :aria-label="__('Edit :meal', ['meal' => $meal->name])" />
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $meal->id }})" :aria-label="__('Remove :meal', ['meal' => $meal->name])" />
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>

    <flux:modal name="plan-week" class="md:w-[28rem]">
        <form wire:submit="plan" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Plan the week with Claude') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Claude suggests meals for the empty slots. Nothing is added to Skylight until you approve it.') }}</flux:text>
            </div>

            <fieldset>
                <legend class="mb-2 text-sm font-medium">{{ __('Plan') }}</legend>
                <div class="flex gap-4">
                    @foreach (Meal::Categories as $category)
                        <label wire:key="plan-{{ $category }}" class="flex items-center gap-2 text-sm">
                            <input type="checkbox" wire:model="planCategories" value="{{ $category }}" class="size-4 accent-zinc-800 dark:accent-zinc-200" />
                            {{ $category }}
                        </label>
                    @endforeach
                </div>
                <flux:error name="planCategories" />
            </fieldset>

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" :placeholder="__('e.g. Sunday vegetarian, quick weeknights, no mushrooms')" />

            <div class="flex items-center justify-end gap-3">
                <flux:text size="sm" wire:loading wire:target="plan">{{ __('Planning… this can take a minute.') }}</flux:text>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="plan">{{ __('Suggest meals') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="shopping-list" class="md:w-[32rem]">
        @if ($proposals === [])
            <form wire:submit="buildShoppingList" class="flex flex-col gap-6">
                <div>
                    <flux:heading size="lg">{{ __('Shopping list from meals') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Claude works out the ingredients for the approved meals in these dates, leaving out what\'s already on the list. You review them before anything is added.') }}</flux:text>
                </div>

                <flux:select wire:model="shoppingListId" :label="__('Add to')">
                    @foreach ($this->shoppingLists as $shoppingList)
                        <flux:select.option :value="$shoppingList->id">{{ $shoppingList->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-3">
                    <flux:input type="date" wire:model="shopFrom" :label="__('From')" />
                    <flux:input type="date" wire:model="shopTo" :label="__('To')" />
                </div>

                <div class="flex items-center justify-end gap-3">
                    <flux:text size="sm" wire:loading wire:target="buildShoppingList">{{ __('Working out ingredients…') }}</flux:text>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="buildShoppingList">{{ __('Work out ingredients') }}</flux:button>
                </div>
            </form>
        @else
            <form wire:submit="addProposals" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Review the shopping list') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Untick anything you already have.') }}</flux:text>
                </div>

                <div class="flex max-h-[50vh] flex-col gap-4 overflow-y-auto">
                    @foreach ($this->proposalsBySection as $sectionName => $sectionProposals)
                        <div wire:key="proposal-section-{{ $sectionName }}" class="flex flex-col gap-1">
                            <flux:heading size="sm" class="text-zinc-500 uppercase">{{ $sectionName }}</flux:heading>

                            @foreach ($sectionProposals as $index => $proposal)
                                <label wire:key="proposal-{{ $index }}" class="flex cursor-pointer items-start gap-3 rounded-lg px-2 py-1 hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <input type="checkbox" wire:model="selectedProposals" value="{{ $index }}" class="mt-1 size-4 shrink-0 accent-zinc-800 dark:accent-zinc-200" />
                                    <span class="flex flex-col">
                                        <span>{{ $proposal['name'] }}</span>
                                        @if ($proposal['meals'] !== [])
                                            <span class="text-xs text-zinc-500">{{ __('For :meals', ['meals' => implode(', ', $proposal['meals'])]) }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-between gap-3">
                    <flux:button variant="ghost" wire:click="startOver">{{ __('Start over') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Add selected items') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>

    <flux:modal name="meal-form" class="md:w-[28rem]">
        <form wire:submit="saveMeal" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ $editingMealId ? __('Edit meal') : __('Add a meal') }}</flux:heading>

            <div class="grid grid-cols-2 gap-3">
                <flux:input type="date" wire:model="mealDate" :label="__('Date')" />
                <flux:select wire:model="mealCategory" :label="__('Meal')">
                    @foreach (Meal::Categories as $category)
                        <flux:select.option :value="$category">{{ $category }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="mealName" :label="__('Name')" />
            <flux:textarea wire:model="mealDescription" :label="__('Ingredients')" rows="4" :placeholder="__('One per line')" />
            <flux:input wire:model="mealNote" :label="__('Note')" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
