<?php

use App\Services\Dashboard\Dashboard;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function summary(): array
    {
        return app(Dashboard::class)->summary();
    }

    public function refresh(): void
    {
        unset($this->summary);
    }

    public function weatherIcon(string $icon): string
    {
        return match ($icon) {
            'sun' => 'sun',
            'moon' => 'moon',
            'storm' => 'bolt',
            'rain' => 'cloud-arrow-down',
            'snow' => 'sparkles',
            default => 'cloud',
        };
    }

    public function hour(string $iso): string
    {
        return CarbonImmutable::parse($iso)->timezone(config('app.display_timezone'))->format('g A');
    }

    public function clock(string $iso): string
    {
        return CarbonImmutable::parse($iso)->timezone(config('app.display_timezone'))->format('g:i A');
    }
}; ?>

<div wire:poll.300s="refresh" class="mx-auto flex max-w-3xl flex-col gap-8">
    <flux:heading size="xl">{{ __('Today') }}</flux:heading>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg">{{ __('Weather') }}</flux:heading>

        @if ($this->summary['weather'])
            @php($weather = $this->summary['weather'])

            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-4">
                    <flux:icon :name="$this->weatherIcon($weather['icon'])" class="size-12 text-zinc-500" />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="text-4xl font-semibold text-zinc-900 dark:text-white">{{ $weather['temperature_f'] }}°</span>
                            <flux:text>{{ $weather['conditions'] }}</flux:text>
                        </div>
                        <flux:text size="sm">
                            {{ $weather['location'] }}
                            @if ($weather['high_f'] !== null && $weather['low_f'] !== null)
                                · {{ __('High :high°, low :low°', ['high' => $weather['high_f'], 'low' => $weather['low_f']]) }}
                            @endif
                            @if ($weather['precip_chance'] !== null)
                                · {{ __(':chance% chance of precipitation', ['chance' => $weather['precip_chance']]) }}
                            @endif
                        </flux:text>
                    </div>
                </div>

                @foreach ($weather['alerts'] as $alert)
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="$alert['headline']" />
                @endforeach

                @if ($weather['hourly'])
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                        @foreach ($weather['hourly'] as $hour)
                            <div wire:key="hour-{{ $hour['time'] }}" class="flex flex-col items-center gap-1 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800">
                                <flux:text size="sm">{{ $this->hour($hour['time']) }}</flux:text>
                                <flux:icon :name="$this->weatherIcon($hour['icon'])" class="size-5 text-zinc-500" />
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $hour['temperature_f'] }}°</span>
                                @if ($hour['precip_chance'])
                                    <flux:text size="sm">{{ $hour['precip_chance'] }}%</flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <flux:callout icon="cloud" :heading="__('Weather isn\'t available right now.')" :text="$this->summary['weather_error'] ?? __('Check back in a few minutes.')" />
        @endif
    </section>

    <section class="flex flex-col gap-4">
        <flux:heading size="lg">{{ __('Schedule') }}</flux:heading>

        @if ($this->summary['schedule_error'])
            <flux:callout variant="warning" icon="calendar" :heading="__('The calendar isn\'t available right now.')" :text="$this->summary['schedule_error']" />
        @endif

        @if (! $this->summary['home_address_set'])
            <flux:callout icon="map-pin" :heading="__('Set your home address')">
                <flux:callout.text>
                    {{ __('Add it on the household page to see drive times to today\'s events.') }}
                    <flux:callout.link :href="route('household')" wire:navigate>{{ __('Go to household') }}</flux:callout.link>
                </flux:callout.text>
            </flux:callout>
        @elseif ($this->summary['drive_error'])
            <flux:callout variant="warning" icon="map-pin" :heading="__('Drive times aren\'t available right now.')" :text="$this->summary['drive_error']" />
        @endif

        @foreach ($this->summary['schedule'] as $day)
            <div wire:key="day-{{ $day['date'] }}" class="flex flex-col gap-2">
                <flux:heading>{{ __($day['label']) }}</flux:heading>

                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($day['events'] as $event)
                        <div wire:key="event-{{ $day['date'] }}-{{ $loop->index }}" class="flex flex-col gap-1 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <flux:text class="font-medium text-zinc-900 dark:text-white">{{ $event['title'] }}</flux:text>
                                <flux:text size="sm" class="shrink-0">{{ $event['all_day'] ? __('All day') : $this->clock($event['start']).' – '.$this->clock($event['end']) }}</flux:text>
                            </div>

                            <flux:text size="sm">
                                {{ $event['calendar'] }}
                                @if ($event['location'])
                                    · {{ $event['location'] }}
                                @endif
                            </flux:text>

                            @if ($event['drive'])
                                <div>
                                    <flux:button size="xs" icon="map-pin" :href="$event['drive']['maps_url']" target="_blank">
                                        {{ __(':minutes min · leave by :time', ['minutes' => $event['drive']['minutes'], 'time' => $this->clock($event['drive']['leave_by'])]) }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <flux:text class="p-3">{{ __('Nothing scheduled.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        @endforeach
    </section>
</div>
