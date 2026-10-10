<?php

namespace App\NativeComponents\Today;

use App\Icons\Android;
use App\Icons\Ios;
use App\NativeComponents\Chat\Concerns\CallsChatApi;
use App\Services\Api\ApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\View\View;
use Native\Mobile\Attributes\Computed;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Facades\Browser;

/**
 * The household's day at a glance: weather, the next few days of calendar events, and drive times.
 */
class TodayScreen extends NativeComponent
{
    use CallsChatApi;

    /**
     * @var array<string, mixed>
     */
    public array $summary = [];

    public bool $loaded = false;

    public string $error = '';

    public function mount(): void
    {
        $this->refresh();
    }

    public function onResume(): void
    {
        $this->refresh();
    }

    public function navTitle(): string
    {
        return 'Today';
    }

    public function refresh(): void
    {
        $response = $this->request(fn (ApiClient $api): Response => $api->get('/dashboard'));

        $this->loaded = true;

        if ($response?->successful() && is_array($response->json('data'))) {
            $this->summary = $response->json('data');
            $this->error = '';

            return;
        }

        $this->error = $this->errorMessage($response, 'Could not load today. Pull down to try again.');
    }

    /**
     * Opens the Apple Maps directions for one event's drive.
     */
    public function openDrive(int $day, int $event): void
    {
        $url = $this->summary['schedule'][$day]['events'][$event]['drive']['maps_url'] ?? null;

        if (is_string($url) && $url !== '') {
            Browser::open($url);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function weather(): ?array
    {
        $weather = $this->summary['weather'] ?? null;

        if (! is_array($weather)) {
            return null;
        }

        $weather['symbol'] = $this->weatherIcon((string) ($weather['icon'] ?? ''));
        $weather['range'] = $this->weatherRange($weather);
        $weather['hourly'] = array_map(fn (array $hour): array => [
            'time' => $this->clock($hour['time'] ?? null),
            'temperature' => ($hour['temperature_f'] ?? '–').'°',
            'precip' => isset($hour['precip_chance']) && $hour['precip_chance'] > 0 ? $hour['precip_chance'].'%' : '',
            'symbol' => $this->weatherIcon((string) ($hour['icon'] ?? '')),
        ], $weather['hourly'] ?? []);

        return $weather;
    }

    /**
     * @return list<array{label: string, events: list<array<string, mixed>>}>
     */
    #[Computed]
    public function days(): array
    {
        return array_map(fn (array $day): array => [
            'label' => (string) $day['label'],
            'events' => array_map(fn (array $event): array => [
                'title' => (string) $event['title'],
                'calendar' => (string) ($event['calendar'] ?? ''),
                'when' => $this->eventTime($event),
                'location' => (string) ($event['location'] ?? ''),
                'drive' => $this->driveLabel($event['drive'] ?? null),
            ], $day['events'] ?? []),
        ], $this->summary['schedule'] ?? []);
    }

    public function render(): View
    {
        return view('native.today.today-screen');
    }

    /**
     * @param  array<string, mixed>  $weather
     */
    protected function weatherRange(array $weather): string
    {
        $parts = [];

        if (isset($weather['high_f'])) {
            $parts[] = 'High '.$weather['high_f'].'°';
        }

        if (isset($weather['low_f'])) {
            $parts[] = 'Low '.$weather['low_f'].'°';
        }

        if (isset($weather['precip_chance'])) {
            $parts[] = $weather['precip_chance'].'% chance of rain';
        }

        return implode(' · ', $parts);
    }

    /**
     * @return array{ios: Ios, android: Android}
     */
    protected function weatherIcon(string $icon): array
    {
        return match ($icon) {
            'sun' => ['ios' => Ios::SunMax, 'android' => Android::WbSunny],
            'moon' => ['ios' => Ios::Moon, 'android' => Android::NightsStay],
            'partly-cloudy' => ['ios' => Ios::CloudSun, 'android' => Android::WbCloudy],
            'rain' => ['ios' => Ios::CloudRain, 'android' => Android::Umbrella],
            'snow' => ['ios' => Ios::CloudSnow, 'android' => Android::AcUnit],
            'storm' => ['ios' => Ios::CloudBolt, 'android' => Android::Thunderstorm],
            'fog' => ['ios' => Ios::CloudFog, 'android' => Android::Cloud],
            'wind' => ['ios' => Ios::Wind, 'android' => Android::Air],
            default => ['ios' => Ios::Cloud, 'android' => Android::Cloud],
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function eventTime(array $event): string
    {
        if ($event['all_day'] ?? false) {
            return 'All day';
        }

        if (empty($event['start'])) {
            return (string) ($event['when'] ?? '');
        }

        $start = $this->clock($event['start']);

        return empty($event['end']) ? $start : $start.' – '.$this->clock($event['end']);
    }

    /**
     * @param  array<string, mixed>|null  $drive
     */
    protected function driveLabel(?array $drive): string
    {
        if ($drive === null) {
            return '';
        }

        $label = $drive['minutes'].' min';

        if (! empty($drive['leave_by'])) {
            $label .= ' · leave by '.$this->clock($drive['leave_by']);
        }

        return $label;
    }

    protected function clock(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '';
        }

        return CarbonImmutable::parse($timestamp)->setTimezone('America/New_York')->format('g:i A');
    }
}
