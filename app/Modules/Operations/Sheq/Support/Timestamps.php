<?php

namespace App\Modules\Operations\Sheq\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Normalises a moment to UTC before it is written.
 *
 * Laravel binds a DateTimeInterface by formatting it — `$value->format('Y-m-d H:i:s')` — which
 * discards the offset. PostgreSQL then reads that naive string in the session's zone. So a Carbon
 * carrying 14:00+02:00 is bound as "14:00:00" and stored as 14:00 UTC: two hours later than the
 * moment it represents.
 *
 * SHEQ computes deliberately in local time — a seven-day statutory deadline runs in the site's
 * timezone, business-day counting needs local weekends, and a fire watch is measured where the work
 * happened (ObligationScheduler, PermitService). Those computations must stay local; only the
 * storage needs to be absolute. This is that boundary.
 *
 * @see docs/adr/SHEQ-ADR-005-timezone-discrepancy.md
 */
final class Timestamps
{
    /** UTC equivalent of a moment, ready to bind. Null passes through so callers can stay terse. */
    public static function utc(DateTimeInterface|string|null $moment): ?Carbon
    {
        if ($moment === null) {
            return null;
        }

        return ($moment instanceof DateTimeInterface ? Carbon::instance($moment) : Carbon::parse($moment))
            ->utc();
    }

    /**
     * The default zone for a site whose own is not set.
     *
     * Africa/Johannesburg rather than UTC, because this product ships into southern Africa and a
     * site with no zone recorded is overwhelmingly more likely to be in SAST than at Greenwich.
     * Guessing UTC would put every unconfigured site's day boundary two hours out, which is the
     * failure this helper exists to stop.
     */
    public const FALLBACK_ZONE = 'Africa/Johannesburg';

    /**
     * A site's own timezone, or the fallback (REG-FIX-6).
     *
     * `sheq_sites.timezone` has been there all along and almost nothing read it.
     */
    public static function zoneFor(?int $siteId, ?int $companyId = null): string
    {
        if ($siteId === null) {
            return self::FALLBACK_ZONE;
        }

        $zone = DB::table('sheq_sites')
            ->where('id', $siteId)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->value('timezone');

        // A zone string the server does not recognise is worse than none: `Carbon::parse` throws
        // on it, so an attendance screen would 500 because somebody typed a city name.
        return is_string($zone) && $zone !== '' && in_array($zone, timezone_identifiers_list(), true)
            ? $zone
            : self::FALLBACK_ZONE;
    }

    /**
     * The UTC window covering one calendar day AT A SITE (REG-FIX-6).
     *
     * This is the fix for a bug that had been reported twice and documented once. "Movements
     * today" was computed as UTC midnight to UTC midnight and compared against stored UTC
     * timestamps — which is internally consistent and wrong, because the day a supervisor means
     * is the day at their gate. In SAST that window starts two hours late, so:
     *
     *   - a night shift entering at 01:00 local appears on the previous day's list
     *   - the first two hours of every morning are missing from "today"
     *   - a daily report run at 07:00 omits the night shift entirely
     *
     * None of which looks like an error. The list is ordered, plausible and short by exactly the
     * number of people who came in before 02:00.
     *
     * Returned as a pair of UTC moments because that is what the stored column holds; the local
     * reasoning happens here and once.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function siteDay(?int $siteId, ?Carbon $day = null, ?int $companyId = null): array
    {
        $zone = self::zoneFor($siteId, $companyId);

        // The calendar day as it is at the site, not as it is on the server. On a server running
        // UTC at 23:30 SAST it is already tomorrow at the gate.
        $local = $day === null
            ? Carbon::now($zone)
            : $day->copy()->setTimezone($zone);

        return [
            Carbon::parse($local->format('Y-m-d').' 00:00:00', $zone)->utc(),
            Carbon::parse($local->format('Y-m-d').' 23:59:59.999999', $zone)->utc(),
        ];
    }

    /**
     * The UTC window covering a range of calendar days at a site.
     *
     * Inclusive of both ends, which is what a date filter on a screen means — somebody choosing
     * "1st to 7th" expects the 7th included, and an exclusive end silently loses a day's data
     * every time.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public static function siteRange(?int $siteId, Carbon $from, Carbon $to,
        ?int $companyId = null): array
    {
        [$start] = self::siteDay($siteId, $from, $companyId);
        [, $end] = self::siteDay($siteId, $to, $companyId);

        return $start->lte($end) ? [$start, $end] : [$end, $start];
    }

    /** Today's date as the site reads it — for comparing against a `date` column. */
    public static function siteToday(?int $siteId, ?int $companyId = null): Carbon
    {
        return Carbon::now(self::zoneFor($siteId, $companyId))->startOfDay();
    }
}
