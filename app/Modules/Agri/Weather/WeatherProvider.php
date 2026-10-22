<?php

namespace App\Modules\Agri\Weather;

/**
 * An approved weather provider (Phase 2 §11.1). Every reading comes back in one shape — UTC
 * periods, °C, %, km/h, mm — so rules and screens never care which provider sent it.
 */
interface WeatherProvider
{
    /**
     * 'daily' (optional) is one row per forecast day in the history() shape, with et0_mm where the
     * provider computes reference evapotranspiration.
     *
     * @return array{daily?: list<array>, current: ?array, hourly: list<array{period_start: string, period_end: string, temperature: ?float, humidity: ?float, wind_speed: ?float, wind_gust: ?float, rain_mm: ?float, rain_probability: ?int}>}
     */
    public function forecast(float $latitude, float $longitude, int $days): array;

    /** @return list<array{period_start: string, period_end: string, temp_min: ?float, temp_max: ?float, rain_mm: ?float, et0_mm?: ?float}> daily observations */
    public function history(float $latitude, float $longitude, string $from, string $to): array;

    /** @return list<array{external_id: string, kind: string, severity: string, starts_at: string, ends_at: ?string, message: string}> */
    public function warnings(float $latitude, float $longitude): array;
}
