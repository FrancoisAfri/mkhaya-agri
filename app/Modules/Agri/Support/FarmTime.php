<?php

namespace App\Modules\Agri\Support;

use App\Modules\Agri\Models\Farm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Farm time. The application stores and computes in UTC, as every module assumes; a farm lives in
 * its own zone (farms.timezone). People see and type times in farm time, and the rules that depend
 * on the clock on the wall — night hours, Sundays, the date a shift belongs to, a shift template's
 * "06:00" — work in farm time.
 *
 * Three places do the converting, so the rest of the code need not think about it:
 *  - the FarmDateTime cast reads farm-time columns back in the farm's zone and writes them as UTC;
 *  - the database connection turns any date-time bound to a query into UTC;
 *  - the FarmTimeInput middleware reads date-times typed on farm screens in the farm's zone.
 */
class FarmTime
{
    /** @var array<int, string> */
    private static array $zones = [];

    private static ?string $requestZone = null;

    /** @var array<int, string> */
    private static array $companyZones = [];

    public static function zone(Model|int|null $farm = null): string
    {
        $id = $farm instanceof Farm ? $farm->id : ($farm instanceof Model ? $farm->getAttribute('farm_id') : $farm);
        if ($id) {
            return self::$zones[(int) $id] ??= self::valid(Farm::withoutGlobalScopes()->whereKey($id)->value('timezone'));
        }

        return self::$requestZone ?? self::companyZone();
    }

    /** The zone a request works in when it names no farm: the one its farm screens were opened for. */
    public static function useZone(?string $zone): void
    {
        self::$requestZone = $zone ? self::valid($zone) : null;
    }

    /** The zone most of the organisation's farms are in; UTC when it has none. */
    public static function companyZone(?int $companyId = null): string
    {
        $companyId ??= auth()->user()?->company_id;
        if (! $companyId) {
            return config('app.timezone');
        }
        if (isset(self::$companyZones[$companyId])) {
            return self::$companyZones[$companyId];
        }
        $zone = Farm::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('archived_at')
            ->selectRaw('timezone, count(*) as n')->groupBy('timezone')->orderByDesc('n')->value('timezone');

        return self::$companyZones[$companyId] = self::valid($zone);
    }

    /** A moment, shown in the farm's zone. */
    public static function local(?\DateTimeInterface $at, Model|int|null $farm = null): ?Carbon
    {
        return $at ? Carbon::instance($at)->setTimezone(self::zone($farm)) : null;
    }

    /** Today on the farm — which, early in the morning, is not today in UTC. */
    public static function today(Model|int|null $farm = null): Carbon
    {
        return Carbon::now(self::zone($farm))->startOfDay();
    }

    /** A wall-clock time on a farm date ("2026-10-21" + "06:00"), as the instant it is. */
    public static function at(string $date, string $time, Model|int|null $farm = null): Carbon
    {
        return Carbon::parse("{$date} {$time}", self::zone($farm));
    }

    public static function forget(): void
    {
        self::$zones = [];
        self::$companyZones = [];
        self::$requestZone = null;
    }

    private static function valid(?string $zone): string
    {
        return $zone && in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : config('app.timezone');
    }
}
