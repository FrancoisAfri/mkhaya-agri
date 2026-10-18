<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Support\LabourRates;
use Illuminate\Support\Carbon;

/**
 * Works out how much of a pay period somebody was actually employed for.
 *
 * The run took a person's monthly package, scaled it to the length of the pay period, and paid it.
 * It never looked at when they started or when they left — so somebody who joined on the 20th was
 * paid the whole month, and somebody who left on the 3rd was paid to the 31st. That is not a
 * missing feature, it is money leaving the account.
 *
 * The convention here is working days: days employed within the period that are not weekends and
 * not public holidays, over the working days in the whole period. That is what most South African
 * monthly payrolls use, and it means a person who starts on the Monday of the last week gets a
 * fifth of the month rather than a quarter of it. Calendar days are available for employers whose
 * contracts say so, because both are defensible and the contract decides which.
 *
 * A full period returns exactly 1.0 — never 0.999 from a rounding path — because the overwhelming
 * majority of payslips are full months and none of them should drift.
 */
class ProRataCalculator
{
    public function __construct(private readonly PayrollService $payroll)
    {
    }

    /**
     * @return array{
     *   fraction: float, days_paid: int, days_in_period: int,
     *   partial: bool, reason: ?string, basis: string
     * }
     */
    public function forPeriod(
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        Carbon|string|null $joined = null,
        Carbon|string|null $left = null,
    ): array {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();
        $basis = $this->basis();

        $joinedOn = $this->date($joined);
        $leftOn = $this->date($left);

        // The window they were actually employed for, clipped to this period.
        $from = $joinedOn && $joinedOn->gt($start) ? $joinedOn->copy()->startOfDay() : $start->copy();
        $to = $leftOn && $leftOn->lt($end) ? $leftOn->copy()->endOfDay() : $end->copy();

        // Left before the period started, or starts after it ended: nothing is owed for it.
        if ($to->lt($from)) {
            return [
                'fraction' => 0.0, 'days_paid' => 0,
                'days_in_period' => $this->countDays($start, $end, $basis),
                'partial' => true, 'basis' => $basis,
                'reason' => $leftOn && $leftOn->lt($start)
                    ? 'Left before this period began.'
                    : 'Starts after this period ended.',
            ];
        }

        $daysInPeriod = $this->countDays($start, $end, $basis);
        $daysPaid = $this->countDays($from, $to, $basis);

        $full = ! $joinedOn || $joinedOn->lte($start);
        $full = $full && (! $leftOn || $leftOn->gte($end->copy()->startOfDay()));

        if ($full) {
            // Exactly one, not a division that happens to come out at one.
            return [
                'fraction' => 1.0, 'days_paid' => $daysInPeriod, 'days_in_period' => $daysInPeriod,
                'partial' => false, 'reason' => null, 'basis' => $basis,
            ];
        }

        return [
            'fraction'       => $daysInPeriod > 0 ? round($daysPaid / $daysInPeriod, 6) : 0.0,
            'days_paid'      => $daysPaid,
            'days_in_period' => $daysInPeriod,
            'partial'        => true,
            'basis'          => $basis,
            'reason'         => $this->reason($joinedOn, $leftOn, $start, $end),
        ];
    }

    /** The amount to actually pay, rounded to the cent at the end rather than along the way. */
    public function apply(float $fullAmount, array $proRata): float
    {
        return round($fullAmount * $proRata['fraction'], 2);
    }

    /** Convenience for the run: everything needed for one person in one call. */
    public function forPerson(object $person, Carbon|string $periodStart, Carbon|string $periodEnd): array
    {
        return $this->forPeriod(
            $periodStart,
            $periodEnd,
            $this->fromStoredDate($person->date_joined ?? null),
            $this->fromStoredDate($person->date_left ?? null),
        );
    }

    // ── Counting ─────────────────────────────────────────────────────────────

    private function countDays(Carbon $from, Carbon $to, string $basis): int
    {
        if ($basis === 'calendar') {
            return $from->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        }

        $holidays = $this->holidays($from, $to);
        $days = 0;
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();

        while ($cursor->lte($last)) {
            if (! $cursor->isWeekend() && ! in_array($cursor->toDateString(), $holidays, true)) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    /** @return string[] */
    private function holidays(Carbon $from, Carbon $to): array
    {
        try {
            // The run already knows how to read these, including the mixed storage formats.
            return $this->payroll->publicHolidaysBetween($from->toDateString(), $to->toDateString());
        } catch (\Throwable) {
            return [];
        }
    }

    private function basis(): string
    {
        $configured = (string) config('payroll.pro_rata_basis', 'working');

        return in_array($configured, ['working', 'calendar'], true) ? $configured : 'working';
    }

    private function reason(?Carbon $joined, ?Carbon $left, Carbon $start, Carbon $end): string
    {
        $parts = [];
        if ($joined && $joined->gt($start)) {
            $parts[] = 'started ' . $joined->toDateString();
        }
        if ($left && $left->lt($end->copy()->startOfDay())) {
            $parts[] = 'left ' . $left->toDateString();
        }

        return $parts === [] ? 'Partial period.' : 'Employed part of the period: ' . implode(', ', $parts) . '.';
    }

    private function date(Carbon|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The people table stores some dates as Unix timestamps and some as strings, which has caught
     * this module out before — Carbon::parse on a bigint throws.
     */
    private function fromStoredDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
