<?php

namespace App\Modules\HR\Payroll\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The labour-law figures that apply on a given date.
 *
 * These used to be PHP constants — `NMW_HOURLY = 28.79`, `MAX_OT_HOURS_PER_WEEK = 10`,
 * `MIN_OT_MULTIPLIER = 1.5`. They change every March, so every March somebody had to edit a class
 * that decides whether a payroll is flagged as underpaying, and deploy it. Now they are rows with
 * effective dates, which also means a payslip reprinted for an old period is measured against the
 * rate that applied then rather than today's.
 *
 * Resolved by date, not by "current", for exactly that reason.
 */
class LabourRates
{
    /**
     * The row in force on a date, or null if none is loaded.
     *
     * Memoised per date because a 400-employee run asks the same question 400 times.
     */
    public function on(Carbon|string|null $date = null, ?string $sector = null, string $country = 'ZA'): ?object
    {
        $day = Carbon::parse($date ?? now())->toDateString();
        $key = 'labour_rates:v' . self::version() . ":{$country}:" . ($sector ?? '-') . ":{$day}";

        return Cache::remember($key, 3600, function () use ($day, $sector, $country) {
            $query = fn ($s) => DB::table('sa_labour_rates')
                ->where('country', $country)
                ->when($s === null, fn ($q) => $q->whereNull('sector'), fn ($q) => $q->where('sector', $s))
                ->whereDate('effective_from', '<=', $day)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
                ->orderByDesc('effective_from')
                ->first();

            // A sectoral determination wins where one exists; otherwise the general one applies.
            return ($sector ? $query($sector) : null) ?? $query(null);
        });
    }

    /**
     * Bumped when a determination is captured or edited.
     *
     * The cache key carries the date, so there is no fixed set of keys to forget — and the driver
     * here is not guaranteed to support tags. A version in the key retires every stale entry at
     * once without touching anything else in the cache, which a flush() would not.
     */
    public static function version(): int
    {
        return (int) Cache::get('labour_rates:version', 1);
    }

    public static function flush(): void
    {
        Cache::forever('labour_rates:version', self::version() + 1);
    }

    /**
     * The National Minimum Wage as a monthly figure.
     *
     * Returned as monthly because that is what a basic salary is compared against, and doing the
     * multiplication at each call site is how two places end up disagreeing about the month length.
     */
    public function minimumMonthlyWage(Carbon|string|null $date = null, ?string $sector = null): float
    {
        $row = $this->on($date, $sector);

        return $row ? round((float) $row->nmw_hourly * (float) $row->standard_monthly_hours, 2) : 0.0;
    }

    public function minimumHourlyWage(Carbon|string|null $date = null, ?string $sector = null): float
    {
        return (float) ($this->on($date, $sector)->nmw_hourly ?? 0);
    }

    public function standardMonthlyHours(Carbon|string|null $date = null, ?string $sector = null): float
    {
        return (float) ($this->on($date, $sector)->standard_monthly_hours ?? 160);
    }

    public function maxOvertimeHoursPerWeek(Carbon|string|null $date = null, ?string $sector = null): int
    {
        return (int) ($this->on($date, $sector)->max_overtime_hours_per_week ?? 10);
    }

    /** BCEA §10 — overtime at one and a half times ordinary pay, unless a sector agrees more. */
    public function overtimeMultiplier(Carbon|string|null $date = null, ?string $sector = null): float
    {
        return (float) ($this->on($date, $sector)->overtime_multiplier ?? 1.5);
    }

    /** BCEA §16 — Sunday work at double, for an employee who does not ordinarily work Sundays. */
    public function sundayMultiplier(Carbon|string|null $date = null, ?string $sector = null): float
    {
        return (float) ($this->on($date, $sector)->sunday_multiplier ?? 2.0);
    }

    /** BCEA §18 — a public holiday worked is paid at double. */
    public function publicHolidayMultiplier(Carbon|string|null $date = null, ?string $sector = null): float
    {
        return (float) ($this->on($date, $sector)->public_holiday_multiplier ?? 2.0);
    }

    /** The annual earnings ceiling COIDA assesses up to. */
    public function coidaCeiling(Carbon|string|null $date = null): ?float
    {
        $value = $this->on($date)?->coida_earnings_ceiling;

        return $value === null ? null : (float) $value;
    }

    /** Everything in force on a date, for a screen or a report that shows the lot. */
    public function all(Carbon|string|null $date = null, ?string $sector = null): array
    {
        $row = $this->on($date, $sector);

        return $row ? (array) $row : [];
    }
}
