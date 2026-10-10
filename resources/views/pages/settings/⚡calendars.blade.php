<?php

use App\Models\SharedCalendar;
use App\Services\MacHelper\MacHelperException;
use App\Services\Calendar\MacCalendar;
use App\Services\Calendar\MacCalendarInfo;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendars')] class extends Component {
    /**
     * The calendars on this Mac, plus any shared ones it no longer has.
     *
     * @var list<array{id: string, title: string, account: string, color: string|null}>
     */
    #[Locked]
    public array $calendars = [];

    #[Locked]
    public ?string $error = null;

    /**
     * Whose each calendar is, by its position in $calendars.
     *
     * @var array<int, string>
     */
    public array $labels = [];

    public function mount(MacCalendar $macCalendar): void
    {
        try {
            $this->calendars = array_map(fn (MacCalendarInfo $info): array => [
                'id' => $info->id,
                'title' => $info->title,
                'account' => $info->account,
                'color' => $info->color,
            ], $macCalendar->calendars());
        } catch (MacHelperException $exception) {
            $this->error = $exception->getMessage();
        }

        $onThisMac = array_column($this->calendars, 'id');

        foreach (SharedCalendar::whereNotIn('calendar_id', $onThisMac)->orderBy('title')->get() as $missing) {
            $this->calendars[] = ['id' => $missing->calendar_id, 'title' => $missing->title, 'account' => '', 'color' => null];
        }

        $labels = SharedCalendar::pluck('label', 'calendar_id');

        foreach ($this->calendars as $index => $calendar) {
            $this->labels[$index] = (string) ($labels[$calendar['id']] ?? '');
        }
    }

    /**
     * Share a calendar with the household, or stop sharing it.
     */
    public function toggle(int $index): void
    {
        $calendar = $this->calendars[$index] ?? abort(404);
        $shared = SharedCalendar::firstWhere('calendar_id', $calendar['id']);

        if ($shared !== null) {
            $shared->delete();
        } else {
            SharedCalendar::create([
                'calendar_id' => $calendar['id'],
                'title' => $calendar['title'],
                'label' => trim($this->labels[$index] ?? '') ?: null,
            ]);
        }

        unset($this->sharedIds);
    }

    public function updatedLabels(mixed $value, int|string $index): void
    {
        $this->validate(['labels.*' => ['nullable', 'string', 'max:100']]);

        $calendar = $this->calendars[(int) $index] ?? abort(404);

        SharedCalendar::where('calendar_id', $calendar['id'])->update(['label' => trim((string) $value) ?: null]);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function sharedIds(): array
    {
        return SharedCalendar::pluck('calendar_id')->all();
    }

    /**
     * @return array<string, array<int, array{id: string, title: string, account: string, color: string|null}>>
     */
    #[Computed]
    public function byAccount(): array
    {
        return collect($this->calendars)
            ->groupBy(fn (array $calendar): string => $calendar['account'] ?: __('No longer on this Mac'), preserveKeys: true)
            ->map(fn ($calendars) => $calendars->all())
            ->all();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Calendars') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Calendars')" :subheading="__('Share calendars from this Mac so chat and the meal planner know what the household has on')">
        @if ($error)
            <flux:callout variant="warning" icon="exclamation-triangle" class="my-6" :heading="__('Can\'t read this Mac\'s calendars')">
                <flux:callout.text>{{ $error }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:text class="my-6">
            {{ __('Calendars come from the accounts in macOS Calendar, such as Google. Shared calendars are visible in everyone\'s chats. Add whose each one is, so Claude can tell them apart.') }}
        </flux:text>

        <div class="flex flex-col gap-8">
            @foreach ($this->byAccount as $account => $calendars)
                <div wire:key="account-{{ $account }}" class="flex flex-col gap-3">
                    <flux:heading>{{ $account }}</flux:heading>

                    @foreach ($calendars as $index => $calendar)
                        <div wire:key="calendar-{{ $calendar['id'] }}" class="flex items-center gap-3">
                            <flux:switch
                                :checked="in_array($calendar['id'], $this->sharedIds, true)"
                                wire:click="toggle({{ $index }})"
                                :aria-label="__('Share :calendar', ['calendar' => $calendar['title']])"
                            />

                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $calendar['color'] ?? '#a1a1aa' }}"></span>

                            <flux:text class="w-40 truncate font-medium text-zinc-900 dark:text-white">{{ $calendar['title'] }}</flux:text>

                            @if (in_array($calendar['id'], $this->sharedIds, true))
                                <flux:input size="sm" wire:model.blur="labels.{{ $index }}" :placeholder="__('Whose? e.g. Vince – Work')" class="flex-1" />
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
