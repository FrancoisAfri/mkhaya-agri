<?php

namespace App\Modules\Operations\ProjectManagement\Support\Recurrence;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An RFC 5545 RRULE, expanded to dates (PM-1122).
 *
 * Deliberately a subset, and deliberately explicit about it: FREQ, INTERVAL, COUNT, UNTIL, BYDAY,
 * BYMONTHDAY, BYMONTH and WKST. That covers what a task recurrence actually needs — "every second
 * Tuesday", "the last Friday of the month", "1 March each year". Anything outside it is rejected by
 * name rather than quietly ignored, because a rule that silently loses BYSETPOS produces a schedule
 * that is wrong on exactly the dates the author cared about.
 *
 * Pure: no database, no clock of its own, no side effects. Everything it needs is an argument,
 * which is what makes the fixtures in RruleTest meaningful (PM-ADR-005 applies the same rule to the
 * scheduling engine).
 */
final class Rrule
{
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    private const SUPPORTED = ['FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'WKST'];

    /** ISO weekday numbers, so the arithmetic below never depends on the locale's first day. */
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * A rule that matches nothing — "every 29 February on a non-leap decade" — must still stop.
     * Periods examined, not occurrences produced.
     */
    private const MAX_PERIODS = 10000;

    private function __construct(
        public readonly string $freq,
        public readonly int $interval,
        public readonly ?int $count,
        public readonly ?CarbonImmutable $until,
        /** @var list<array{ordinal:int|null,day:int}> */
        public readonly array $byDay,
        /** @var list<int> */
        public readonly array $byMonthDay,
        /** @var list<int> */
        public readonly array $byMonth,
        public readonly int $weekStart,
    ) {
    }

    public static function parse(string $rrule): self
    {
        $body = trim(preg_replace('/^RRULE:/i', '', trim($rrule)));

        if ($body === '') {
            throw new InvalidArgumentException('The recurrence rule is empty.');
        }

        $parts = [];

        foreach (explode(';', $body) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            if (! str_contains($segment, '=')) {
                throw new InvalidArgumentException("'{$segment}' is not a NAME=VALUE pair.");
            }

            [$key, $value] = explode('=', $segment, 2);
            $key = strtoupper(trim($key));

            if (! in_array($key, self::SUPPORTED, true)) {
                throw new InvalidArgumentException(
                    "{$key} is not supported in a recurrence rule. Supported: " . implode(', ', self::SUPPORTED) . '.'
                );
            }

            $parts[$key] = strtoupper(trim($value));
        }

        $freq = $parts['FREQ'] ?? throw new InvalidArgumentException('The recurrence rule has no FREQ.');

        if (! in_array($freq, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException(
                "FREQ={$freq} is not supported. Supported: " . implode(', ', self::FREQUENCIES) . '.'
            );
        }

        if (isset($parts['COUNT']) && isset($parts['UNTIL'])) {
            throw new InvalidArgumentException('A recurrence rule may set COUNT or UNTIL, not both.');
        }

        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;

        if ($interval < 1) {
            throw new InvalidArgumentException('INTERVAL must be 1 or more.');
        }

        $count = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;

        if ($count !== null && $count < 1) {
            throw new InvalidArgumentException('COUNT must be 1 or more.');
        }

        return new self(
            freq:       $freq,
            interval:   $interval,
            count:      $count,
            until:      isset($parts['UNTIL']) ? self::parseUntil($parts['UNTIL']) : null,
            byDay:      isset($parts['BYDAY']) ? self::parseByDay($parts['BYDAY'], $freq) : [],
            byMonthDay: isset($parts['BYMONTHDAY']) ? self::parseIntList($parts['BYMONTHDAY'], 'BYMONTHDAY', 1, 31, true) : [],
            byMonth:    isset($parts['BYMONTH']) ? self::parseIntList($parts['BYMONTH'], 'BYMONTH', 1, 12, false) : [],
            weekStart:  isset($parts['WKST']) ? (self::DAYS[$parts['WKST']] ?? throw new InvalidArgumentException("WKST={$parts['WKST']} is not a day.")) : 1,
        );
    }

    /**
     * The occurrences of this rule that fall in [$from, $to].
     *
     * Expansion always begins at $start, never at $from: COUNT counts from the first occurrence, so
     * a window that starts later still has to know how many came before it. That is why asking for
     * "next month" of a COUNT=5 rule correctly returns nothing once those five are spent.
     *
     * @param  CarbonImmutable  $start  DTSTART — supplies the time of day for every occurrence
     * @return list<CarbonImmutable>
     */
    public function between(CarbonImmutable $start, CarbonImmutable $from, CarbonImmutable $to, int $limit = 500): array
    {
        if ($limit < 1 || $to < $from) {
            return [];
        }

        $results = [];
        $emitted = 0;

        for ($period = 0; $period < self::MAX_PERIODS; $period++) {
            $candidates = $this->candidatesFor($start, $period);

            // An empty period is not the end of the rule: a MONTHLY BYMONTHDAY=31 skips February
            // and carries on in March. Only UNTIL, COUNT or $to end it.
            foreach ($candidates as $candidate) {
                if ($candidate < $start) {
                    continue;
                }

                if ($this->until && $candidate > $this->until) {
                    return $results;
                }

                $emitted++;

                if ($this->count !== null && $emitted > $this->count) {
                    return $results;
                }

                if ($candidate > $to) {
                    return $results;
                }

                if ($candidate >= $from) {
                    $results[] = $candidate;

                    if (count($results) >= $limit) {
                        return $results;
                    }
                }
            }

            // Nothing can be produced after the window closes, so stop walking empty periods.
            if ($this->periodStart($start, $period) > $to) {
                return $results;
            }
        }

        return $results;
    }

    /** A plain-language rendering, for the UI and for anything that has to explain itself. */
    public function describe(): string
    {
        $every = $this->interval === 1
            ? ['DAILY' => 'Every day', 'WEEKLY' => 'Every week', 'MONTHLY' => 'Every month', 'YEARLY' => 'Every year'][$this->freq]
            : 'Every ' . $this->interval . ' ' . ['DAILY' => 'days', 'WEEKLY' => 'weeks', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$this->freq];

        $names = array_flip(self::DAYS);
        $full = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];

        if ($this->byDay !== []) {
            $days = array_map(function (array $d) use ($names, $full) {
                $name = $full[$names[$d['day']]];
                $ordinal = match ($d['ordinal']) {
                    null => '', 1 => 'first ', 2 => 'second ', 3 => 'third ', 4 => 'fourth ', -1 => 'last ',
                    default => $d['ordinal'] . 'th ',
                };

                return $ordinal . $name;
            }, $this->byDay);

            $every .= ' on the ' . implode(', ', $days);
        } elseif ($this->byMonthDay !== []) {
            $every .= ' on day ' . implode(', ', $this->byMonthDay);
        }

        if ($this->count !== null) {
            $every .= ', ' . $this->count . ' times';
        } elseif ($this->until) {
            $every .= ', until ' . $this->until->toFormattedDateString();
        }

        return $every;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The dates this rule produces in one period, in order.
     *
     * @return list<CarbonImmutable>
     */
    private function candidatesFor(CarbonImmutable $start, int $period): array
    {
        $anchor = $this->periodStart($start, $period);

        $dates = match ($this->freq) {
            'DAILY'   => [$anchor],
            'WEEKLY'  => $this->weekCandidates($start, $anchor),
            'MONTHLY' => $this->monthCandidates($start, $anchor),
            'YEARLY'  => $this->yearCandidates($start, $anchor),
        };

        $dates = array_values(array_filter(
            $dates,
            fn (?CarbonImmutable $d) => $d !== null && $this->passesFilters($d)
        ));

        usort($dates, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return array_map(fn (CarbonImmutable $d) => $this->atTimeOf($d, $start), $dates);
    }

    private function periodStart(CarbonImmutable $start, int $period): CarbonImmutable
    {
        $step = $period * $this->interval;

        return match ($this->freq) {
            'DAILY'   => $start->startOfDay()->addDays($step),
            // Anchored to the week containing DTSTART, respecting WKST, so BYDAY expands within
            // the right seven days rather than a Monday-based approximation of them.
            'WEEKLY'  => $this->startOfWeek($start)->addWeeks($step),
            // Month and year anchors are the first of the period: adding months to the 31st would
            // otherwise skid into the next month and shift every later period with it.
            'MONTHLY' => $start->startOfMonth()->addMonthsNoOverflow($step),
            'YEARLY'  => $start->startOfYear()->addYears($step),
        };
    }

    /** @return list<CarbonImmutable> */
    private function weekCandidates(CarbonImmutable $start, CarbonImmutable $weekStart): array
    {
        $days = $this->byDay !== [] ? array_column($this->byDay, 'day') : [$start->dayOfWeekIso];

        return array_map(function (int $iso) use ($weekStart) {
            $offset = ($iso - $this->weekStart + 7) % 7;

            return $weekStart->addDays($offset);
        }, array_values(array_unique($days)));
    }

    /** @return list<CarbonImmutable> */
    private function monthCandidates(CarbonImmutable $start, CarbonImmutable $monthStart): array
    {
        if ($this->byDay !== []) {
            return $this->nthWeekdaysIn($monthStart, $monthStart->endOfMonth()->startOfDay());
        }

        $daysOfMonth = $this->byMonthDay !== [] ? $this->byMonthDay : [$start->day];

        return array_values(array_filter(array_map(
            fn (int $day) => $this->dayOfMonth($monthStart, $day),
            $daysOfMonth
        )));
    }

    /** @return list<CarbonImmutable> */
    private function yearCandidates(CarbonImmutable $start, CarbonImmutable $yearStart): array
    {
        $months = $this->byMonth !== [] ? $this->byMonth : [$start->month];
        $dates = [];

        foreach ($months as $month) {
            $monthStart = $yearStart->setMonth($month)->startOfMonth();

            if ($this->byDay !== []) {
                $dates = array_merge($dates, $this->nthWeekdaysIn($monthStart, $monthStart->endOfMonth()->startOfDay()));

                continue;
            }

            $daysOfMonth = $this->byMonthDay !== [] ? $this->byMonthDay : [$start->day];

            foreach ($daysOfMonth as $day) {
                if ($date = $this->dayOfMonth($monthStart, $day)) {
                    $dates[] = $date;
                }
            }
        }

        return $dates;
    }

    /**
     * BYDAY inside one month, honouring the ordinal: 2FR is the second Friday, -1MO the last
     * Monday. Without an ordinal, every matching weekday in the month.
     *
     * @return list<CarbonImmutable>
     */
    private function nthWeekdaysIn(CarbonImmutable $monthStart, CarbonImmutable $monthEnd): array
    {
        $dates = [];

        foreach ($this->byDay as $rule) {
            $matching = [];

            for ($d = $monthStart; $d <= $monthEnd; $d = $d->addDay()) {
                if ($d->dayOfWeekIso === $rule['day']) {
                    $matching[] = $d;
                }
            }

            if ($rule['ordinal'] === null) {
                $dates = array_merge($dates, $matching);

                continue;
            }

            $index = $rule['ordinal'] > 0 ? $rule['ordinal'] - 1 : count($matching) + $rule['ordinal'];

            if (isset($matching[$index])) {
                $dates[] = $matching[$index];
            }
        }

        return $dates;
    }

    /**
     * Day N of the given month, counting back from the end when negative. Returns null when the
     * month is too short — RFC 5545 skips such months rather than clamping, and clamping would
     * silently turn "the 31st" into "the 28th" every February.
     */
    private function dayOfMonth(CarbonImmutable $monthStart, int $day): ?CarbonImmutable
    {
        $length = $monthStart->daysInMonth;
        $resolved = $day > 0 ? $day : $length + $day + 1;

        if ($resolved < 1 || $resolved > $length) {
            return null;
        }

        return $monthStart->setDay($resolved);
    }

    /** BYMONTH and BYMONTHDAY act as filters when the frequency did not already expand on them. */
    private function passesFilters(CarbonImmutable $date): bool
    {
        if ($this->byMonth !== [] && in_array($this->freq, ['DAILY', 'WEEKLY', 'MONTHLY'], true)
            && ! in_array($date->month, $this->byMonth, true)) {
            return false;
        }

        if ($this->byMonthDay !== [] && in_array($this->freq, ['DAILY', 'WEEKLY'], true)) {
            $fromEnd = $date->day - $date->daysInMonth - 1;

            if (! in_array($date->day, $this->byMonthDay, true) && ! in_array($fromEnd, $this->byMonthDay, true)) {
                return false;
            }
        }

        if ($this->byDay !== [] && $this->freq === 'DAILY'
            && ! in_array($date->dayOfWeekIso, array_column($this->byDay, 'day'), true)) {
            return false;
        }

        return true;
    }

    private function atTimeOf(CarbonImmutable $date, CarbonImmutable $start): CarbonImmutable
    {
        return $date->setTime($start->hour, $start->minute, $start->second);
    }

    private function startOfWeek(CarbonImmutable $date): CarbonImmutable
    {
        $offset = ($date->dayOfWeekIso - $this->weekStart + 7) % 7;

        return $date->startOfDay()->subDays($offset);
    }

    private static function parseUntil(string $value): CarbonImmutable
    {
        // UNTIL is UTC by definition when it carries a Z, and a bare date otherwise.
        foreach (['Ymd\THis\Z', 'Ymd\THis', 'Ymd'] as $format) {
            $parsed = CarbonImmutable::createFromFormat($format, $value, 'UTC');

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $format === 'Ymd' ? $parsed->endOfDay() : $parsed;
            }
        }

        throw new InvalidArgumentException("UNTIL={$value} is not a valid date; expected 20260131T000000Z.");
    }

    /** @return list<array{ordinal:int|null,day:int}> */
    private static function parseByDay(string $value, string $freq): array
    {
        $rules = [];

        foreach (explode(',', $value) as $token) {
            if (! preg_match('/^(-?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim($token), $m)) {
                throw new InvalidArgumentException("BYDAY={$token} is not a day; expected MO, 2FR or -1SU.");
            }

            $ordinal = ($m[1] ?? '') === '' ? null : (int) $m[1];

            if ($ordinal !== null && ! in_array($freq, ['MONTHLY', 'YEARLY'], true)) {
                throw new InvalidArgumentException(
                    "BYDAY={$token} uses an ordinal, which only means something with FREQ=MONTHLY or FREQ=YEARLY."
                );
            }

            if ($ordinal === 0) {
                throw new InvalidArgumentException('BYDAY ordinals start at 1 (or -1 counting back).');
            }

            $rules[] = ['ordinal' => $ordinal, 'day' => self::DAYS[$m[2]]];
        }

        return $rules;
    }

    /** @return list<int> */
    private static function parseIntList(string $value, string $key, int $min, int $max, bool $allowNegative): array
    {
        $values = [];

        foreach (explode(',', $value) as $token) {
            $token = trim($token);

            if (! preg_match('/^-?\d+$/', $token)) {
                throw new InvalidArgumentException("{$key}={$token} is not a number.");
            }

            $number = (int) $token;
            $magnitude = abs($number);

            if ($number === 0 || $magnitude < $min || $magnitude > $max || (! $allowNegative && $number < 0)) {
                throw new InvalidArgumentException("{$key}={$token} is out of range ({$min}–{$max}).");
            }

            $values[] = $number;
        }

        return array_values(array_unique($values));
    }
}
