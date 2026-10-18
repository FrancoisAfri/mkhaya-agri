<?php

namespace App\Shared\Leave;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Turns a date range into the days of leave it actually costs an employee.
 *
 * The answer people care about is a single number, but the number on its own is unarguable in the
 * wrong way — a client told "that is four days, not five" wants to know which day was not counted
 * and why. So the calculator returns the working, one row per calendar day, and the total is the
 * sum of it. Those rows land in bureau_leave_application_days and are what the payroll export
 * splits on when a leave period straddles two pay periods.
 *
 * Stateless on purpose: give it a calendar and it will answer for any employer, any tenant, any
 * year, with no database behind it.
 */
class LeaveDayCalculator
{
    public const FULL = 'full';
    public const FIRST_HALF = 'first_half';
    public const SECOND_HALF = 'second_half';
    public const HOURLY = 'hourly';

    /**
     * Break a leave request down day by day.
     *
     * @param  string  $dayPortion  full | first_half | second_half | hourly — a half day applies to
     *                              the first or last day of a range, never to the days in between.
     * @return array{
     *     days: float,
     *     calendar_days: int,
     *     hours: float,
     *     breakdown: array<int, array{date:string, days:float, hours:float, is_working_day:bool, exclusion_reason:?string}>
     * }
     */
    public function breakdown(
        WorkingCalendar $calendar,
        Carbon|string $start,
        Carbon|string $end,
        string $dayPortion = self::FULL,
    ): array {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();

        if ($to->lessThan($from)) {
            return ['days' => 0.0, 'calendar_days' => 0, 'hours' => 0.0, 'breakdown' => []];
        }

        $rows = [];
        $total = 0.0;
        $calendarDays = 0;

        foreach (CarbonPeriod::create($from, $to) as $date) {
            $calendarDays++;
            [$value, $reason] = $this->valueFor($calendar, $date);

            // A half-day request only shortens the boundary it applies to. Asking for a "first half"
            // across a fortnight means the first morning is off, not ten half days.
            if ($value > 0 && $this->isBoundaryHalfDay($dayPortion, $date, $from, $to)) {
                $value = round($value / 2, 4);
                $reason = 'half_day_requested';
            }

            $rows[] = [
                'date'             => $date->toDateString(),
                'days'             => $value,
                'hours'            => round($value * $calendar->hoursPerDay, 2),
                'is_working_day'   => $value > 0,
                'exclusion_reason' => $reason,
            ];

            $total += $value;
        }

        return [
            'days'          => round($total, 4),
            'calendar_days' => $calendarDays,
            'hours'         => round($total * $calendar->hoursPerDay, 2),
            'breakdown'     => $rows,
        ];
    }

    /** Just the number, when the working is not needed. */
    public function days(WorkingCalendar $calendar, Carbon|string $start, Carbon|string $end, string $dayPortion = self::FULL): float
    {
        return $this->breakdown($calendar, $start, $end, $dayPortion)['days'];
    }

    /**
     * Hours between two clock times on one day, for employers who track leave hourly.
     * Never negative, and never more than the calendar says a day is worth.
     */
    public function hoursBetween(WorkingCalendar $calendar, Carbon|string $date, string $startTime, string $endTime): float
    {
        $day = Carbon::parse($date)->toDateString();
        $from = Carbon::parse("{$day} {$startTime}");
        $to = Carbon::parse("{$day} {$endTime}");

        if ($to->lessThanOrEqualTo($from)) {
            return 0.0;
        }

        return round(min($from->diffInMinutes($to) / 60, $calendar->hoursPerDay), 2);
    }

    /**
     * Part of a single day, expressed as clock times.
     *
     * Returned in the same shape as breakdown() so an hourly request travels through the rest of the
     * system as an ordinary application — the fraction of a day it costs is what the ledger stores,
     * and the hours are carried alongside for payroll, which usually wants both.
     *
     * A non-working day yields nothing: a Sunday between nine and eleven is not two hours of leave.
     *
     * @return array{days: float, calendar_days: int, hours: float, breakdown: array<int, array{
     *     date:string, days:float, hours:float, is_working_day:bool, exclusion_reason:?string}>}
     */
    public function hourlyBreakdown(
        WorkingCalendar $calendar,
        Carbon|string $date,
        string $startTime,
        string $endTime,
    ): array {
        $day = Carbon::parse($date)->startOfDay();
        [$value, $reason] = $this->valueFor($calendar, $day);

        if ($value <= 0) {
            return [
                'days' => 0.0, 'calendar_days' => 1, 'hours' => 0.0,
                'breakdown' => [[
                    'date' => $day->toDateString(), 'days' => 0.0, 'hours' => 0.0,
                    'is_working_day' => false, 'exclusion_reason' => $reason,
                ]],
            ];
        }

        $hours = $this->hoursBetween($calendar, $day, $startTime, $endTime);

        // A half day is worth half a day whatever the calendar says the hours are, so the fraction
        // is taken against the day's own length rather than a notional eight hours.
        $dayHours = $calendar->hoursPerDay * $value;
        $days = $dayHours > 0 ? round(min($hours, $dayHours) / $calendar->hoursPerDay, 4) : 0.0;

        return [
            'days'          => $days,
            'calendar_days' => 1,
            'hours'         => round(min($hours, $dayHours), 2),
            'breakdown'     => [[
                'date'             => $day->toDateString(),
                'days'             => $days,
                'hours'            => round(min($hours, $dayHours), 2),
                'is_working_day'   => true,
                'exclusion_reason' => $days > 0 ? 'hourly_requested' : 'no_hours_requested',
            ]],
        ];
    }

    /** The date N working days after a given one — used for approval deadlines and notice periods. */
    public function addWorkingDays(WorkingCalendar $calendar, Carbon|string $from, int $workingDays): Carbon
    {
        $date = Carbon::parse($from)->startOfDay();
        $added = 0;
        $guard = 0;

        while ($added < $workingDays && $guard++ < 3650) {
            $date->addDay();
            if ($this->valueFor($calendar, $date)[0] > 0) {
                $added++;
            }
        }

        return $date;
    }

    /**
     * What one day is worth, and the reason when it is worth nothing.
     *
     * Order matters. A special working day beats everything, because it exists precisely to
     * override the calendar. After that a holiday beats the weekday pattern — a public holiday on a
     * Wednesday is not worked even though Wednesday normally is.
     *
     * @return array{0: float, 1: ?string}
     */
    private function valueFor(WorkingCalendar $calendar, Carbon $date): array
    {
        if ($calendar->isSpecialWorkingDay($date)) {
            return [$calendar->isHalfDay($date) ? 0.5 : 1.0, null];
        }

        if ($calendar->isHoliday($date)) {
            return [0.0, 'public_holiday'];
        }

        if (! $calendar->isWorkingWeekday($date)) {
            return [0.0, $date->isWeekend() ? 'weekend' : 'non_working'];
        }

        if ($calendar->isHalfDay($date)) {
            return [0.5, 'half_day'];
        }

        return [1.0, null];
    }

    /** Whether a half-day request applies to this particular date. */
    private function isBoundaryHalfDay(string $dayPortion, Carbon $date, Carbon $from, Carbon $to): bool
    {
        return match ($dayPortion) {
            self::FIRST_HALF  => $date->isSameDay($from),
            self::SECOND_HALF => $date->isSameDay($to),
            default           => false,
        };
    }
}
