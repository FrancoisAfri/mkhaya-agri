<?php

namespace App\Modules\Tenancy\Services;

use Illuminate\Support\Carbon;

/**
 * The launch-promotion window. For the first `launch_months` from `launch_date`
 * every plan bills at its launch (promotional) price; after it closes, plans bill
 * at their regular price — except founding customers, whose per-company
 * `price_locked_until` keeps them on the launch price for a fixed term.
 */
class LaunchWindow
{
    public static function start(): Carbon
    {
        return Carbon::parse(config('edition.launch_date', '2026-07-01'))->startOfDay();
    }

    public static function end(): Carbon
    {
        return self::start()->copy()->addMonths((int) config('edition.launch_months', 6));
    }

    /** Is the launch promotion currently running? */
    public static function isOpen(): bool
    {
        return now()->betweenIncluded(self::start(), self::end());
    }

    /**
     * The date a founding customer's launch price is locked until, if they sign up
     * now (today + founding_lock_months). Returns null once the window has closed.
     */
    public static function foundingLockUntil(): ?Carbon
    {
        if (! self::isOpen()) {
            return null;
        }

        return now()->copy()->addMonths((int) config('edition.founding_lock_months', 12))->startOfDay();
    }
}
