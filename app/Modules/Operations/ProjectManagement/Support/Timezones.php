<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use DateTimeZone;
use Illuminate\Support\Facades\Cache;

/**
 * IANA timezone options for the PM pickers.
 *
 * A timezone is never a free-text field in this module: every timestamp is stored UTC with the
 * zone it was observed in (DoD 7), so a typo is not a cosmetic problem — it fails validation at
 * best and mislabels an instant at worst.
 *
 * Labels carry the current offset ("Africa/Johannesburg (+02:00)") because "Africa/Blantyre" means
 * nothing to most people while "+02:00" does. The offset is the *current* one, so it moves with
 * daylight saving — which is honest: it tells you what the zone means today, and the stored value
 * is the zone name, not the offset.
 */
class Timezones
{
    /** Offsets shift twice a year, so a day of caching is plenty and never stale enough to matter. */
    private const CACHE_TTL = 86400;

    /** @return list<array{value:string,title:string}> */
    public static function options(): array
    {
        return Cache::remember('pm.timezones.' . date('Y-m-d'), self::CACHE_TTL, function () {
            $options = [];

            foreach (DateTimeZone::listIdentifiers() as $identifier) {
                $options[] = [
                    'value' => $identifier,
                    'title' => $identifier . ' (' . self::offset($identifier) . ')',
                ];
            }

            return $options;
        });
    }

    public static function isValid(string $identifier): bool
    {
        return in_array($identifier, DateTimeZone::listIdentifiers(), true);
    }

    private static function offset(string $identifier): string
    {
        $offset = (new DateTimeZone($identifier))->getOffset(new \DateTimeImmutable('now', new DateTimeZone('UTC')));

        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }
}
