<?php

namespace App\Modules\Agri\Weather;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Open-Meteo (open-meteo.com): forecasts and historical observations without an account. It
 * publishes no official warnings, so warnings() returns none — frost, heat and wind are caught by
 * the farm's own approved rules on the forecast instead, and an official warnings feed is a
 * separate provider.
 */
class OpenMeteoProvider implements WeatherProvider
{
    public function __construct(private readonly array $config = []) {}

    public function forecast(float $latitude, float $longitude, int $days): array
    {
        $r = Http::timeout(15)->retry(2, 500)->get($this->config['forecast_url'] ?? 'https://api.open-meteo.com/v1/forecast', [
            'latitude' => $latitude, 'longitude' => $longitude, 'forecast_days' => max(1, min(16, $days)), 'timezone' => 'UTC', 'wind_speed_unit' => 'kmh',
            'current' => 'temperature_2m,relative_humidity_2m,wind_speed_10m,wind_gusts_10m,precipitation',
            'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,precipitation_probability,wind_speed_10m,wind_gusts_10m',
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('Open-Meteo forecast failed: HTTP '.$r->status());
        }
        $j = $r->json();
        $h = $j['hourly'] ?? [];
        $hourly = [];
        foreach ($h['time'] ?? [] as $i => $t) {
            $start = Carbon::parse($t, 'UTC');
            $hourly[] = ['period_start' => $start->toIso8601String(), 'period_end' => $start->copy()->addHour()->toIso8601String(),
                'temperature' => $h['temperature_2m'][$i] ?? null, 'humidity' => $h['relative_humidity_2m'][$i] ?? null, 'wind_speed' => $h['wind_speed_10m'][$i] ?? null,
                'wind_gust' => $h['wind_gusts_10m'][$i] ?? null, 'rain_mm' => $h['precipitation'][$i] ?? null,
                'rain_probability' => isset($h['precipitation_probability'][$i]) ? (int) $h['precipitation_probability'][$i] : null];
        }
        $c = $j['current'] ?? null;

        return ['current' => $c ? ['period_start' => Carbon::parse($c['time'], 'UTC')->toIso8601String(), 'period_end' => Carbon::parse($c['time'], 'UTC')->addMinutes((int) ($c['interval'] ?? 900) / 60)->toIso8601String(),
            'temperature' => $c['temperature_2m'] ?? null, 'humidity' => $c['relative_humidity_2m'] ?? null, 'wind_speed' => $c['wind_speed_10m'] ?? null,
            'wind_gust' => $c['wind_gusts_10m'] ?? null, 'rain_mm' => $c['precipitation'] ?? null, 'rain_probability' => null] : null, 'hourly' => $hourly];
    }

    public function history(float $latitude, float $longitude, string $from, string $to): array
    {
        $r = Http::timeout(20)->retry(2, 500)->get($this->config['archive_url'] ?? 'https://archive-api.open-meteo.com/v1/archive', [
            'latitude' => $latitude, 'longitude' => $longitude, 'start_date' => $from, 'end_date' => $to, 'timezone' => 'UTC',
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('Open-Meteo history failed: HTTP '.$r->status());
        }
        $d = $r->json('daily') ?? [];
        $out = [];
        foreach ($d['time'] ?? [] as $i => $day) {
            $out[] = ['period_start' => Carbon::parse($day, 'UTC')->toIso8601String(), 'period_end' => Carbon::parse($day, 'UTC')->addDay()->toIso8601String(),
                'temp_min' => $d['temperature_2m_min'][$i] ?? null, 'temp_max' => $d['temperature_2m_max'][$i] ?? null, 'rain_mm' => $d['precipitation_sum'][$i] ?? null];
        }

        return $out;
    }

    public function warnings(float $latitude, float $longitude): array
    {
        return [];
    }
}
