<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Towns and cities, for the places PM asks where something is.
 *
 * A free-text town field looks harmless and is not: "Jhb", "Joburg", "Johannesburg" and
 * "johannesburg " are four clients in the same city as far as any grouping, filter or report is
 * concerned, and nothing downstream can put them back together. The platform already keeps a
 * `cities` reference table, so PM reads that rather than starting a fifth spelling of its own.
 *
 * Cached, because it is reference data that changes about never and is asked for on every render of
 * a form.
 */
final class Cities
{
    private const CACHE_KEY = 'pm.cities.options';

    private const CACHE_TTL = 3600;

    /**
     * Names, alphabetical.
     *
     * Returns an empty list if the table is not there — a fresh install or a slimmed deployment.
     * The screens treat that as "no list to offer" rather than breaking, which is the difference
     * between a missing dropdown and a 500 on the client form.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            if (! Schema::hasTable('cities')) {
                return [];
            }

            return DB::table('cities')->orderBy('name')->pluck('name')->unique()->values()->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
