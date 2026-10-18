<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Working-time arithmetic: the calendar the scheduling engine counts in (PM-1230).
 *
 * A duration is working minutes, never elapsed ones. "Two days" means the second working day, not
 * forty-eight hours later, and a job that finishes at knock-off finishes that evening rather than
 * the next morning — because that is what the person reading the schedule means by it.
 *
 * The calendar is wall-clock local time, and deliberately so. A shift is 07:00–16:00 on both sides
 * of a clock change; the day the clocks move is an hour shorter in real time and nobody on site
 * notices, which is exactly the behaviour a schedule should reproduce (PM-ADR-005).
 *
 * Pure: constructed from arrays, no database, no clock of its own.
 */
final class WorkingTime
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * How far the arithmetic will walk looking for a working minute before giving up. Two years of
     * days — long enough for any real shutdown, short enough that a calendar with no working time
     * left (every day excepted, say) fails loudly rather than spinning.
     */
    private const MAX_DAYS = 730;

    /** @var array<string, list<array{0:int,1:int}>> day key => windows as minutes-from-midnight */
    private readonly array $week;

    /** @var array<string, list<array{0:int,1:int}>> Y-m-d => windows; an empty list means non-working */
    private readonly array $exceptions;

    /**
     * @param  array<string, list<array{0:string,1:string}>>  $week  e.g. ['mon' => [['08:00','17:00']]]
     * @param  array<string, array{is_working:bool, windows?:list<array{0:string,1:string}>}>  $exceptions  keyed Y-m-d
     */
    public function __construct(array $week, private readonly string $timezone, array $exceptions = [])
    {
        $parsed = [];
        $total = 0;

        foreach (self::DAY_KEYS as $day) {
            $parsed[$day] = $this->parseWindows($week[$day] ?? [], $day);
            $total += array_sum(array_map(fn (array $w) => $w[1] - $w[0], $parsed[$day]));
        }

        if ($total === 0) {
            throw new InvalidArgumentException(
                'This calendar has no working time in it, so nothing could ever be scheduled against it.'
            );
        }

        $this->week = $parsed;

        $overrides = [];

        foreach ($exceptions as $date => $rule) {
            $overrides[$date] = ($rule['is_working'] ?? false)
                ? $this->parseWindows($rule['windows'] ?? [], $date)
                : [];
        }

        $this->exceptions = $overrides;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /** Working minutes in a standard week — the "how long is a day" the UI converts durations with. */
    public function minutesPerWeek(): int
    {
        $total = 0;

        foreach ($this->week as $windows) {
            foreach ($windows as [$from, $to]) {
                $total += $to - $from;
            }
        }

        return $total;
    }

    /**
     * Working minutes in a typical day on this calendar — the divisor for turning a duration into
     * the "days" a person thinks in. Derived rather than assumed: eight hours is wrong for a site
     * on nine-hour shifts and very wrong for a round-the-clock one.
     */
    public function minutesPerWorkingDay(): int
    {
        $days = 0;

        foreach ($this->week as $windows) {
            if ($windows !== []) {
                $days++;
            }
        }

        return $days > 0 ? (int) round($this->minutesPerWeek() / $days) : 0;
    }

    public function isWorking(CarbonImmutable $at): bool
    {
        $local = $at->setTimezone($this->timezone);
        $minute = $local->hour * 60 + $local->minute;

        foreach ($this->windowsOn($local) as [$from, $to]) {
            // Half-open: the closing minute is when work stops, not a minute of work. Without that,
            // 17:00 would count as both the end of Monday and a working moment in its own right.
            if ($minute >= $from && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /** The first working moment at or after $from. */
    public function next(CarbonImmutable $from): CarbonImmutable
    {
        $local = $from->setTimezone($this->timezone);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $i === 0 ? $local : $local->addDays($i)->startOfDay();
            $minute = $i === 0 ? $local->hour * 60 + $local->minute : 0;

            foreach ($this->windowsOn($day) as [$start, $end]) {
                if ($minute < $end) {
                    return $this->atMinute($day, max($minute, $start));
                }
            }
        }

        throw new InvalidArgumentException($this->exhausted('forwards', $from));
    }

    /**
     * The last working moment at or before $from.
     *
     * Symmetric with next(): a moment already inside a shift is returned untouched, and one outside
     * lands on the closing minute of the previous shift.
     */
    public function previous(CarbonImmutable $from): CarbonImmutable
    {
        $local = $from->setTimezone($this->timezone);

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $i === 0 ? $local : $local->subDays($i)->startOfDay();
            $minute = $i === 0 ? $local->hour * 60 + $local->minute : 24 * 60;

            foreach (array_reverse($this->windowsOn($day)) as [$start, $end]) {
                if ($minute > $start) {
                    return $this->atMinute($day, min($minute, $end));
                }
            }
        }

        throw new InvalidArgumentException($this->exhausted('backwards', $from));
    }

    /**
     * $from plus $minutes of working time.
     *
     * A zero duration — a milestone — snaps forward to the next working moment and stops there.
     */
    public function add(CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            return $this->subtract($from, -$minutes);
        }

        $cursor = $this->next($from);

        if ($minutes === 0) {
            return $cursor;
        }

        $remaining = $minutes;

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $cursor->startOfDay();
            $minute = $i === 0 ? $cursor->hour * 60 + $cursor->minute : 0;

            foreach ($this->windowsOn($day) as [$start, $end]) {
                if ($minute >= $end) {
                    continue;
                }

                $available = $end - max($minute, $start);

                // <= is what keeps a finish at knock-off on the same evening: consuming exactly the
                // rest of the shift ends at its closing minute rather than rolling to the next one.
                if ($remaining <= $available) {
                    return $this->atMinute($day, max($minute, $start) + $remaining);
                }

                $remaining -= $available;
            }

            $cursor = $day->addDay()->startOfDay();
        }

        throw new InvalidArgumentException($this->exhausted('forwards', $from));
    }

    /** $from minus $minutes of working time — the exact inverse of add(). */
    public function subtract(CarbonImmutable $from, int $minutes): CarbonImmutable
    {
        if ($minutes < 0) {
            return $this->add($from, -$minutes);
        }

        $cursor = $this->previous($from);

        if ($minutes === 0) {
            return $cursor;
        }

        $remaining = $minutes;

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            $day = $cursor->startOfDay();
            $minute = $i === 0 ? $cursor->hour * 60 + $cursor->minute : 24 * 60;

            foreach (array_reverse($this->windowsOn($day)) as [$start, $end]) {
                if ($minute <= $start) {
                    continue;
                }

                $available = min($minute, $end) - $start;

                if ($remaining <= $available) {
                    return $this->atMinute($day, min($minute, $end) - $remaining);
                }

                $remaining -= $available;
            }

            $cursor = $day->subDay()->endOfDay();
        }

        throw new InvalidArgumentException($this->exhausted('backwards', $from));
    }

    /**
     * Working minutes between two moments. Negative when $b precedes $a, rather than absolute — a
     * negative float is meaningful and hiding its sign would hide the delay it represents.
     */
    public function between(CarbonImmutable $a, CarbonImmutable $b): int
    {
        if ($b < $a) {
            return -$this->between($b, $a);
        }

        $from = $a->setTimezone($this->timezone);
        $to = $b->setTimezone($this->timezone);
        $total = 0;

        for ($day = $from->startOfDay(); $day <= $to; $day = $day->addDay()->startOfDay()) {
            foreach ($this->windowsOn($day) as [$start, $end]) {
                $windowStart = $this->atMinute($day, $start);
                $windowEnd = $this->atMinute($day, $end);

                $overlapStart = $windowStart->max($from);
                $overlapEnd = $windowEnd->min($to);

                if ($overlapEnd > $overlapStart) {
                    $total += (int) round($overlapStart->diffInMinutes($overlapEnd, absolute: true));
                }
            }
        }

        return $total;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array{0:int,1:int}> */
    private function windowsOn(CarbonImmutable $day): array
    {
        $date = $day->format('Y-m-d');

        // A dated exception replaces the weekday entirely — a holiday is not "Monday with less on
        // it", and a Saturday catch-up shift has to work on a day the week says is closed.
        if (array_key_exists($date, $this->exceptions)) {
            return $this->exceptions[$date];
        }

        return $this->week[self::DAY_KEYS[$day->dayOfWeekIso - 1]];
    }

    /**
     * A wall-clock minute on a given day.
     *
     * setTime is what makes the clock-change behaviour right: it names an hour and a minute, so a
     * shift ends at 16:00 whatever happened to the offset that morning. Adding elapsed minutes to
     * midnight would land an hour out on those two days a year.
     */
    private function atMinute(CarbonImmutable $day, int $minute): CarbonImmutable
    {
        return $day->startOfDay()->setTime(intdiv($minute, 60), $minute % 60);
    }

    /**
     * @param  list<array{0:string,1:string}>  $windows
     * @return list<array{0:int,1:int}>
     */
    private function parseWindows(array $windows, string $label): array
    {
        $parsed = [];

        foreach ($windows as $window) {
            if (! is_array($window) || count($window) !== 2) {
                throw new InvalidArgumentException("A working window on {$label} must be [from, to].");
            }

            $from = $this->toMinutes($window[0], $label);
            $to = $this->toMinutes($window[1], $label);

            if ($to <= $from) {
                throw new InvalidArgumentException(
                    "The working window {$window[0]}–{$window[1]} on {$label} ends before it starts."
                );
            }

            $parsed[] = [$from, $to];
        }

        usort($parsed, fn (array $a, array $b) => $a[0] <=> $b[0]);

        // Overlapping windows would double-count their overlap in between(), which is how a
        // fortnight of work quietly becomes ten days.
        foreach ($parsed as $i => [$from, $to]) {
            if ($i > 0 && $from < $parsed[$i - 1][1]) {
                throw new InvalidArgumentException("Working windows on {$label} overlap.");
            }
        }

        return $parsed;
    }

    private function toMinutes(string $time, string $label): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            throw new InvalidArgumentException("'{$time}' on {$label} is not a time of day (HH:MM).");
        }

        $minutes = ((int) $m[1]) * 60 + (int) $m[2];

        // 24:00 is allowed as a closing time — a night shift ends at midnight.
        if ($minutes > 24 * 60 || (int) $m[2] > 59) {
            throw new InvalidArgumentException("'{$time}' on {$label} is not a time of day (HH:MM).");
        }

        return $minutes;
    }

    private function exhausted(string $direction, CarbonImmutable $from): string
    {
        return sprintf(
            'Walked %s two years from %s without finding working time — the calendar is closed for longer than any schedule could span.',
            $direction,
            $from->setTimezone($this->timezone)->toDateString(),
        );
    }
}
