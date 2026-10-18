<?php

namespace App\Modules\HR\Leave\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class WorkingDaysCalculator
{
    private array $publicHolidays = [];

    public function __construct()
    {
        $this->loadPublicHolidays();
    }

    public function calculate(string $start, string $end, bool $sixDayWeek = false): float
    {
        $from   = Carbon::parse($start)->startOfDay();
        $to     = Carbon::parse($end)->startOfDay();
        $period = CarbonPeriod::create($from, $to);

        $days = 0;

        foreach ($period as $date) {
            if ($this->isWorkingDay($date, $sixDayWeek)) {
                $days++;
            }
        }

        return (float) $days;
    }

    public function calculateHours(string $start, string $end, string $startTime, string $endTime): float
    {
        $s = Carbon::parse("{$start} {$startTime}");
        $e = Carbon::parse("{$end} {$endTime}");

        if ($e->lessThanOrEqualTo($s)) {
            return 0.0;
        }

        return (float) round($s->diffInMinutes($e) / 60, 2);
    }

    public function isWorkingDay(Carbon $date, bool $sixDayWeek = false): bool
    {
        if ($date->isSunday()) {
            return false;
        }

        if (! $sixDayWeek && $date->isSaturday()) {
            return false;
        }

        // Check if it's a public holiday that falls on a Sunday —
        // the next Monday is the substitute holiday
        if ($this->isPublicHoliday($date)) {
            return false;
        }

        return true;
    }

    public function isPublicHoliday(Carbon $date): bool
    {
        $ymd = $date->format('Y-m-d');

        if (in_array($ymd, $this->publicHolidays, true)) {
            return true;
        }

        // Easter calculations
        [$goodFriday, $easterSunday, $familyDay] = $this->easterDates($date->year);

        return in_array($ymd, [$goodFriday, $easterSunday, $familyDay], true);
    }

    public function addWorkingDays(Carbon $date, int $workingDays, bool $sixDayWeek = false): Carbon
    {
        $result = $date->copy();
        $added  = 0;

        while ($added < $workingDays) {
            $result->addDay();
            if ($this->isWorkingDay($result, $sixDayWeek)) {
                $added++;
            }
        }

        return $result;
    }

    private function loadPublicHolidays(): void
    {
        $currentYear = (int) date('Y');
        $recurringYears = [$currentYear - 1, $currentYear, $currentYear + 1, $currentYear + 2];

        $dates = [];

        DB::table('public_holidays')
            ->where(function ($q) {
                // Shared defaults (NULL company) + the active tenant's own holidays.
                $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
                $q->whereNull('company_id');
                if ($cid !== null) {
                    $q->orWhere('company_id', $cid);
                }
            })
            ->get(['day', 'year'])->each(function ($row) use ($recurringYears, &$dates) {
            $day  = (string) $row->day;
            $year = (int) ($row->year ?? 0);

            // Resolve the base MM-DD from whatever format day is stored in
            if (is_numeric($day)) {
                $parsed = Carbon::createFromTimestamp((int) $day);
                $mmdd   = $parsed->format('m-d');
            } elseif (strlen($day) > 5) {
                // Full date string e.g. "2025-12-25"
                $parsed = Carbon::parse($day);
                $mmdd   = $parsed->format('m-d');
            } else {
                // Already MM-DD
                $mmdd = $day;
            }

            // year=0 means recurring annually — expand across relevant years
            $targetYears = $year > 0 ? [$year] : $recurringYears;
            foreach ($targetYears as $y) {
                $dates[] = $y . '-' . $mmdd;
            }
        });

        // Sunday-shift rule: when a public holiday falls on Sunday,
        // the following Monday is the substitute working-day off.
        $extras = [];
        foreach ($dates as $d) {
            if (Carbon::parse($d)->isSunday()) {
                $extras[] = Carbon::parse($d)->addDay()->format('Y-m-d');
            }
        }

        $this->publicHolidays = array_unique(array_merge($dates, $extras));
    }

    private function easterDates(int $year): array
    {
        $a = $year % 19;
        $b = (int) ($year / 100);
        $c = $year % 100;
        $d = (int) ($b / 4);
        $e = $b % 4;
        $f = (int) (($b + 8) / 25);
        $g = (int) (($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = (int) ($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = (int) (($a + 11 * $h + 22 * $l) / 451);
        $month  = (int) (($h + $l - 7 * $m + 114) / 31);
        $day    = (($h + $l - 7 * $m + 114) % 31) + 1;

        $easterSunday = Carbon::createFromDate($year, $month, $day);
        $goodFriday   = $easterSunday->copy()->subDays(2);
        $familyDay    = $easterSunday->copy()->addDay();

        return [
            $goodFriday->format('Y-m-d'),
            $easterSunday->format('Y-m-d'),
            $familyDay->format('Y-m-d'),
        ];
    }
}
