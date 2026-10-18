<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowCalendar;
use App\Shared\Leave\SouthAfricanHolidays;
use Carbon\CarbonImmutable;

/**
 * SLA clock (PRS §10). Calendar hours are plain elapsed time; business hours count only the
 * calendar's working window on working days, skipping public holidays (computed South African
 * holidays plus the calendar's own extra days). Times are computed in the calendar's timezone and
 * stored in UTC, so a deadline does not move with the server's zone.
 */
class WorkingTime
{
    private const DEFAULT = ['timezone' => 'Africa/Johannesburg', 'work_days' => [1, 2, 3, 4, 5], 'day_start' => '08:00', 'day_end' => '17:00', 'use_public_holidays' => true, 'extra_holidays' => []];

    public function dueAt(\DateTimeInterface $from, float $hours, bool $businessHours, ?WorkflowCalendar $calendar = null): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from);
        if (! $businessHours) {
            return $from->addMinutes((int) round($hours * 60));
        }
        $c = $this->settings($calendar);
        $t = $from->setTimezone($c['timezone']);
        $remaining = (int) round($hours * 60);

        for ($guard = 0; $guard < 3660 && $remaining > 0; $guard++) {
            [$open, $close] = $this->window($t, $c);
            if ($open === null || $t >= $close) {
                $t = $this->nextDayStart($t, $c);

                continue;
            }
            if ($t < $open) {
                $t = $open;
            }
            $available = (int) floor(($close->getTimestamp() - $t->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $t->addMinutes($remaining)->utc();
            }
            $remaining -= $available;
            $t = $this->nextDayStart($t, $c);
        }

        return $t->utc();
    }

    /** Working minutes between two moments (for reporting active time). */
    public function minutesBetween(\DateTimeInterface $a, \DateTimeInterface $b, bool $businessHours, ?WorkflowCalendar $calendar = null): int
    {
        $a = CarbonImmutable::instance($a);
        $b = CarbonImmutable::instance($b);
        if ($b <= $a) {
            return 0;
        }
        if (! $businessHours) {
            return (int) floor(($b->getTimestamp() - $a->getTimestamp()) / 60);
        }
        $c = $this->settings($calendar);
        $t = $a->setTimezone($c['timezone']);
        $end = $b->setTimezone($c['timezone']);
        $total = 0;
        for ($guard = 0; $guard < 3660 && $t < $end; $guard++) {
            [$open, $close] = $this->window($t, $c);
            if ($open !== null) {
                $s = max($t, $open);
                $e = min($end, $close);
                if ($e > $s) {
                    $total += (int) floor(($e->getTimestamp() - $s->getTimestamp()) / 60);
                }
            }
            $t = $this->nextDayStart($t, $c);
        }

        return $total;
    }

    /** @return array{0:?CarbonImmutable, 1:?CarbonImmutable} today's working window, or nulls on a non-working day */
    private function window(CarbonImmutable $t, array $c): array
    {
        if (! in_array($t->dayOfWeekIso, array_map('intval', $c['work_days']), true) || $this->isHoliday($t, $c)) {
            return [null, null];
        }
        [$sh, $sm] = array_map('intval', explode(':', $c['day_start']));
        [$eh, $em] = array_map('intval', explode(':', $c['day_end']));

        return [$t->setTime($sh, $sm), $t->setTime($eh, $em)];
    }

    private function nextDayStart(CarbonImmutable $t, array $c): CarbonImmutable
    {
        [$sh, $sm] = array_map('intval', explode(':', $c['day_start']));

        return $t->addDay()->setTime($sh, $sm);
    }

    private function isHoliday(CarbonImmutable $t, array $c): bool
    {
        $d = $t->toDateString();
        if (in_array($d, $c['extra_holidays'] ?? [], true)) {
            return true;
        }
        if ($c['use_public_holidays']) {
            static $years = [];
            $years[$t->year] ??= (new SouthAfricanHolidays)->forYear($t->year);

            return isset($years[$t->year][$d]);
        }

        return false;
    }

    private function settings(?WorkflowCalendar $calendar): array
    {
        if (! $calendar) {
            return self::DEFAULT;
        }

        return [
            'timezone' => $calendar->timezone ?: self::DEFAULT['timezone'],
            'work_days' => $calendar->work_days ?: self::DEFAULT['work_days'],
            'day_start' => $calendar->day_start ?: '08:00', 'day_end' => $calendar->day_end ?: '17:00',
            'use_public_holidays' => (bool) $calendar->use_public_holidays,
            'extra_holidays' => $calendar->extra_holidays ?? [],
        ];
    }
}
