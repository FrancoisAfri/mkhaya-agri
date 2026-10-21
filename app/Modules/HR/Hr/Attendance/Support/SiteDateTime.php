<?php

namespace App\Modules\HR\Hr\Attendance\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A moment stored in UTC and read in the time zone of the record's site (or the organisation's). */
class SiteDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $utc = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');

        return $utc->setTimezone(Sites::zone((int) ($attributes['farm_id'] ?? 0) ?: null));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $at = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, config('app.timezone'));

        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }
}
