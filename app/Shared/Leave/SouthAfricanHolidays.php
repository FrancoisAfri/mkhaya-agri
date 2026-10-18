<?php

namespace App\Shared\Leave;

use Carbon\Carbon;

/**
 * The gazetted South African public holidays for a year, computed rather than stored.
 *
 * Two of them move with Easter and one of them is a rule rather than a date: under the Public
 * Holidays Act a holiday falling on a Sunday is observed on the Monday. Getting that wrong quietly
 * overstates every employee's leave by a day whenever it happens, which is often enough to matter
 * and rare enough that nobody notices for a year.
 *
 * Computed so a bureau can set a client up for 2027 without waiting for somebody to load a table.
 */
class SouthAfricanHolidays
{
    /** Fixed-date holidays as month-day, with their gazetted names. */
    private const FIXED = [
        '01-01' => "New Year's Day",
        '03-21' => 'Human Rights Day',
        '04-27' => 'Freedom Day',
        '05-01' => "Workers' Day",
        '06-16' => 'Youth Day',
        '08-09' => "National Women's Day",
        '09-24' => 'Heritage Day',
        '12-16' => 'Day of Reconciliation',
        '12-25' => 'Christmas Day',
        '12-26' => 'Day of Goodwill',
    ];

    /**
     * Every public holiday in a calendar year, including Monday substitutes.
     *
     * @return array<string, string> Y-m-d => name
     */
    public function forYear(int $year): array
    {
        $holidays = [];

        foreach (self::FIXED as $monthDay => $name) {
            $holidays[$year . '-' . $monthDay] = $name;
        }

        [$goodFriday, $familyDay] = $this->easterHolidays($year);
        $holidays[$goodFriday] = 'Good Friday';
        $holidays[$familyDay] = 'Family Day';

        // The Sunday rule. Applied after everything else so a moving holiday is covered too.
        foreach ($holidays as $date => $name) {
            if (Carbon::parse($date)->isSunday()) {
                $monday = Carbon::parse($date)->addDay()->toDateString();
                $holidays[$monday] = $name . ' (observed)';
            }
        }

        ksort($holidays);

        return $holidays;
    }

    /** Just the dates, for handing to a WorkingCalendar. */
    public function datesForYear(int $year): array
    {
        return array_keys($this->forYear($year));
    }

    /** Dates across an inclusive span of years. */
    public function datesForYears(int $from, int $to): array
    {
        $dates = [];
        for ($year = $from; $year <= $to; $year++) {
            $dates = [...$dates, ...$this->datesForYear($year)];
        }

        return array_values(array_unique($dates));
    }

    /**
     * Good Friday and Family Day, from the anonymous Gregorian computus.
     *
     * Good Friday is the Friday before Easter Sunday; Family Day is the Monday after it.
     *
     * @return array{0: string, 1: string}
     */
    private function easterHolidays(int $year): array
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        $easter = Carbon::create($year, $month, $day)->startOfDay();

        return [
            $easter->copy()->subDays(2)->toDateString(),  // Good Friday
            $easter->copy()->addDay()->toDateString(),    // Family Day
        ];
    }
}
