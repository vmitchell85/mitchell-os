<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Current conditions and the outlook for a zip code from the National Weather
 * Service (api.weather.gov), which needs no key. Zip codes are turned into
 * coordinates with zippopotam.us.
 */
class Weather
{
    public const int CacheMinutes = 15;

    /**
     * @return array{
     *     location: string, temperature_f: int, conditions: string, icon: string,
     *     high_f: int|null, low_f: int|null, precip_chance: int|null,
     *     hourly: list<array{time: string, temperature_f: int, conditions: string, icon: string, precip_chance: int|null}>,
     *     alerts: list<array{headline: string, severity: string}>,
     * }
     *
     * @throws WeatherException
     */
    public function forZip(string $zip): array
    {
        $key = "weather.forecast.{$zip}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $forecast = $this->fetch($zip);
        Cache::put($key, $forecast, now()->addMinutes(self::CacheMinutes));

        return $forecast;
    }

    /**
     * @return array{location: string, latitude: float, longitude: float}
     *
     * @throws WeatherException
     */
    public function locate(string $zip): array
    {
        return Cache::rememberForever("weather.zip.{$zip}", function () use ($zip): array {
            $response = $this->request()->get("https://api.zippopotam.us/us/{$zip}");

            $place = $response->json('places.0');

            if (! $response->successful() || ! is_array($place)) {
                throw new WeatherException("I couldn't find the zip code {$zip}.");
            }

            return [
                'location' => "{$place['place name']}, {$place['state abbreviation']} {$zip}",
                'latitude' => (float) $place['latitude'],
                'longitude' => (float) $place['longitude'],
            ];
        });
    }

    /**
     * @return array{
     *     location: string, temperature_f: int, conditions: string, icon: string,
     *     high_f: int|null, low_f: int|null, precip_chance: int|null,
     *     hourly: list<array{time: string, temperature_f: int, conditions: string, icon: string, precip_chance: int|null}>,
     *     alerts: list<array{headline: string, severity: string}>,
     * }
     *
     * @throws WeatherException
     */
    protected function fetch(string $zip): array
    {
        try {
            $place = $this->locate($zip);
            $point = "{$place['latitude']},{$place['longitude']}";

            $points = $this->request()->get("https://api.weather.gov/points/{$point}");

            if (! $points->successful()) {
                throw new WeatherException('The National Weather Service has no forecast for that location.');
            }

            $hourly = $this->periods($points->json('properties.forecastHourly'));
            $daily = $this->periods($points->json('properties.forecast'));
            $alerts = $this->request()->get('https://api.weather.gov/alerts/active', ['point' => $point]);
        } catch (ConnectionException) {
            throw new WeatherException("I couldn't reach the weather service.");
        }

        $now = CarbonImmutable::now();
        $upcoming = array_values(array_filter($hourly, fn (array $period): bool => CarbonImmutable::parse($period['endTime'])->greaterThan($now)));

        if ($upcoming === []) {
            throw new WeatherException('The weather service returned no forecast.');
        }

        [$current, $next] = [$upcoming[0], array_slice($upcoming, 1, 6)];
        [$high, $low] = $this->highAndLow($daily);

        return [
            'location' => $place['location'],
            'temperature_f' => (int) $current['temperature'],
            'conditions' => (string) $current['shortForecast'],
            'icon' => $this->icon((string) $current['shortForecast'], (bool) $current['isDaytime']),
            'high_f' => $high,
            'low_f' => $low,
            'precip_chance' => $this->precipChance($current),
            'hourly' => array_map(fn (array $period): array => [
                'time' => CarbonImmutable::parse($period['startTime'])->utc()->toIso8601String(),
                'temperature_f' => (int) $period['temperature'],
                'conditions' => (string) $period['shortForecast'],
                'icon' => $this->icon((string) $period['shortForecast'], (bool) $period['isDaytime']),
                'precip_chance' => $this->precipChance($period),
            ], $next),
            'alerts' => $alerts->successful() ? $this->alerts($alerts->json('features', [])) : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws WeatherException
     */
    protected function periods(mixed $url): array
    {
        if (! is_string($url) || $url === '') {
            throw new WeatherException('The National Weather Service has no forecast for that location.');
        }

        $response = $this->request()->get($url);

        if (! $response->successful()) {
            throw new WeatherException('The National Weather Service forecast is unavailable right now.');
        }

        return array_values($response->json('properties.periods', []));
    }

    /**
     * Today's (Eastern) daytime and nighttime periods give the high and the low.
     *
     * @param  list<array<string, mixed>>  $periods
     * @return array{0: int|null, 1: int|null}
     */
    protected function highAndLow(array $periods): array
    {
        $timezone = config('app.display_timezone');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $high = $low = null;

        foreach ($periods as $period) {
            if (CarbonImmutable::parse($period['startTime'])->setTimezone($timezone)->toDateString() !== $today) {
                continue;
            }

            if ($period['isDaytime']) {
                $high ??= (int) $period['temperature'];
            } else {
                $low ??= (int) $period['temperature'];
            }
        }

        return [$high, $low];
    }

    /**
     * @param  array<string, mixed>  $period
     */
    protected function precipChance(array $period): ?int
    {
        $value = $period['probabilityOfPrecipitation']['value'] ?? null;

        return is_numeric($value) ? (int) round($value) : null;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return list<array{headline: string, severity: string}>
     */
    protected function alerts(array $features): array
    {
        $alerts = [];

        foreach ($features as $feature) {
            $properties = $feature['properties'] ?? [];
            $headline = (string) ($properties['headline'] ?? $properties['event'] ?? '');

            if ($headline !== '') {
                $alerts[] = ['headline' => $headline, 'severity' => (string) ($properties['severity'] ?? 'Unknown')];
            }
        }

        return $alerts;
    }

    /**
     * One of sun, moon, partly-cloudy, cloud, rain, snow, storm, fog or wind.
     */
    protected function icon(string $conditions, bool $daytime): string
    {
        $text = strtolower($conditions);

        return match (true) {
            str_contains($text, 'thunder'), str_contains($text, 'tornado'), str_contains($text, 'hurricane'), str_contains($text, 'hail') => 'storm',
            str_contains($text, 'snow'), str_contains($text, 'flurries'), str_contains($text, 'sleet'), str_contains($text, 'ice'), str_contains($text, 'wintry') => 'snow',
            str_contains($text, 'rain'), str_contains($text, 'shower'), str_contains($text, 'drizzle') => 'rain',
            str_contains($text, 'fog'), str_contains($text, 'haze'), str_contains($text, 'smoke'), str_contains($text, 'mist'), str_contains($text, 'dust') => 'fog',
            str_contains($text, 'wind'), str_contains($text, 'breezy'), str_contains($text, 'blustery') => 'wind',
            str_contains($text, 'partly'), str_contains($text, 'mostly sunny'), str_contains($text, 'mostly clear') => 'partly-cloudy',
            str_contains($text, 'cloud'), str_contains($text, 'overcast') => 'cloud',
            default => $daytime ? 'sun' : 'moon',
        };
    }

    protected function request(): PendingRequest
    {
        return Http::withUserAgent(config('services.weather.user_agent'))
            ->acceptJson()
            ->timeout(config('services.weather.timeout'));
    }
}
