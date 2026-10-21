<?php

namespace App\Modules\Agri\Casts;

use App\Modules\Agri\Support\FarmTime;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A moment kept in UTC and handed back in the farm's own zone. Comparisons are unaffected (a moment
 * is a moment); formatting, ->hour, ->isSunday() and ->toDateString() read as the farm's clock does.
 */
class FarmDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        $utc = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, 'UTC');

        return $utc->setTimezone(FarmTime::zone((int) ($attributes['farm_id'] ?? 0) ?: null));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // A Carbon or a string with an offset is that moment; a bare string is UTC, as the app's clock is.
        $at = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, config('app.timezone'));

        return $at->copy()->utc()->format('Y-m-d H:i:s');
    }
}
