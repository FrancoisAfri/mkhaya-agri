<?php

namespace App\Support\Database;

use DateTimeInterface;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Carbon;

/**
 * Timestamps are stored in the application's zone (UTC). Laravel writes a bound date-time with its
 * own wall clock and ignores its zone, so a moment held in another zone — a farm's, say — would be
 * stored or compared hours out. Here every bound date-time is first turned into the application's
 * zone. A UTC value, which is nearly all of them, is unchanged.
 */
class UtcPostgresConnection extends PostgresConnection
{
    public function prepareBindings(array $bindings)
    {
        $zone = config('app.timezone');
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface && $value->getTimezone()->getName() !== $zone) {
                $bindings[$key] = Carbon::instance($value)->setTimezone($zone);
            }
        }

        return parent::prepareBindings($bindings);
    }
}
