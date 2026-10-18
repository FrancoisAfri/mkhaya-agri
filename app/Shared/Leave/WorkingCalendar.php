<?php

namespace App\Shared\Leave;

use Carbon\Carbon;

/**
 * A description of which days an employer actually works — everything the day calculator needs,
 * and nothing it has to go and fetch.
 *
 * Deliberately a plain value object with no database access. The existing in-house calculator loads
 * the tenant's public holidays in its constructor, which makes it impossible to compute leave for a
 * client whose calendar is not the current tenant's — exactly what a bureau does all day. Handing
 * the calendar in instead makes the calculation a pure function: same calendar and same dates give
 * the same answer, in a test, in a queue worker, for any of three hundred clients.
 */
final class WorkingCalendar
{
    /**
     * @param  array<int>     $workingWeekdays    ISO weekday numbers worked, 1 = Monday … 7 = Sunday
     * @param  array<string>  $holidays           Y-m-d dates not worked (public, company, shutdown)
     * @param  array<string>  $halfDays           Y-m-d dates worked at half value
     * @param  array<string>  $specialWorkingDays Y-m-d dates worked despite being a weekend or holiday
     * @param  float          $hoursPerDay        length of a full working day
     */
    public function __construct(
        public readonly array $workingWeekdays = [1, 2, 3, 4, 5],
        public readonly array $holidays = [],
        public readonly array $halfDays = [],
        public readonly array $specialWorkingDays = [],
        public readonly float $hoursPerDay = 8.0,
    ) {
    }

    /** The conventional South African five-day week. */
    public static function fiveDayWeek(array $holidays = [], float $hoursPerDay = 8.0): self
    {
        return new self([1, 2, 3, 4, 5], $holidays, [], [], $hoursPerDay);
    }

    /** A six-day week, as worked on many sites and in retail. */
    public static function sixDayWeek(array $holidays = [], float $hoursPerDay = 8.0): self
    {
        return new self([1, 2, 3, 4, 5, 6], $holidays, [], [], $hoursPerDay);
    }

    public function isWorkingWeekday(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->workingWeekdays, true);
    }

    public function isHoliday(Carbon $date): bool
    {
        return in_array($date->toDateString(), $this->holidays, true);
    }

    public function isHalfDay(Carbon $date): bool
    {
        return in_array($date->toDateString(), $this->halfDays, true);
    }

    /**
     * A day the employer works even though the calendar says otherwise — a Saturday catch-up shift,
     * or a public holiday the client has agreed to work. It beats both the weekend and the holiday.
     */
    public function isSpecialWorkingDay(Carbon $date): bool
    {
        return in_array($date->toDateString(), $this->specialWorkingDays, true);
    }

    /** The same calendar with more non-working dates folded in. */
    public function withHolidays(array $dates): self
    {
        return new self(
            $this->workingWeekdays,
            array_values(array_unique([...$this->holidays, ...$dates])),
            $this->halfDays,
            $this->specialWorkingDays,
            $this->hoursPerDay,
        );
    }

    /** Working days in a standard week, used to prorate monthly figures. */
    public function daysPerWeek(): int
    {
        return count($this->workingWeekdays);
    }
}
