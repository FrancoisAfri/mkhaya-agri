<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Recurrence\Rrule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures for the RRULE expander (PM-1122).
 *
 * Written against the rules people actually set on a site — "every second Tuesday", "the last
 * Friday of the month", "the 31st" — and against the two ways a recurrence quietly goes wrong: a
 * month too short for the day it names, and a clock change that moves the time of day.
 *
 * No database and no application: the expander is pure, so these are dates in and dates out.
 */
class RruleTest extends TestCase
{
    /** @return list<string> */
    private function expand(string $rule, string $start, string $from, string $to, string $tz = 'Africa/Johannesburg'): array
    {
        $dates = Rrule::parse($rule)->between(
            CarbonImmutable::parse($start, $tz),
            CarbonImmutable::parse($from, $tz),
            CarbonImmutable::parse($to, $tz),
        );

        return array_map(fn (CarbonImmutable $d) => $d->format('Y-m-d H:i'), $dates);
    }

    public function test_a_daily_rule_produces_consecutive_days(): void
    {
        $this->assertSame(
            ['2026-03-02 07:00', '2026-03-03 07:00', '2026-03-04 07:00'],
            $this->expand('FREQ=DAILY', '2026-03-02 07:00', '2026-03-02', '2026-03-04 23:59')
        );
    }

    public function test_an_interval_skips_periods(): void
    {
        $this->assertSame(
            ['2026-03-02 07:00', '2026-03-05 07:00', '2026-03-08 07:00'],
            $this->expand('FREQ=DAILY;INTERVAL=3', '2026-03-02 07:00', '2026-03-01', '2026-03-09')
        );
    }

    public function test_a_weekly_rule_expands_each_named_day(): void
    {
        // Monday 2 March 2026.
        $this->assertSame(
            ['2026-03-02 06:30', '2026-03-04 06:30', '2026-03-06 06:30', '2026-03-09 06:30'],
            $this->expand('FREQ=WEEKLY;BYDAY=MO,WE,FR', '2026-03-02 06:30', '2026-03-01', '2026-03-09 23:59')
        );
    }

    public function test_a_fortnightly_rule_skips_the_week_between(): void
    {
        $this->assertSame(
            ['2026-03-03 08:00', '2026-03-17 08:00', '2026-03-31 08:00'],
            $this->expand('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU', '2026-03-03 08:00', '2026-03-01', '2026-04-01')
        );
    }

    public function test_the_last_friday_of_each_month(): void
    {
        $this->assertSame(
            ['2026-03-27 15:00', '2026-04-24 15:00', '2026-05-29 15:00'],
            $this->expand('FREQ=MONTHLY;BYDAY=-1FR', '2026-03-01 15:00', '2026-03-01', '2026-05-31')
        );
    }

    public function test_the_second_tuesday_of_each_month(): void
    {
        $this->assertSame(
            ['2026-03-10 09:00', '2026-04-14 09:00'],
            $this->expand('FREQ=MONTHLY;BYDAY=2TU', '2026-03-01 09:00', '2026-03-01', '2026-04-30')
        );
    }

    /**
     * The month that is too short is skipped, not clamped. Clamping would silently turn "the 31st"
     * into "the 28th" every February, and a monthly stock count would drift three days early.
     */
    public function test_a_day_that_does_not_exist_skips_that_month(): void
    {
        $this->assertSame(
            ['2026-01-31 10:00', '2026-03-31 10:00', '2026-05-31 10:00'],
            $this->expand('FREQ=MONTHLY;BYMONTHDAY=31', '2026-01-31 10:00', '2026-01-01', '2026-06-01')
        );
    }

    public function test_a_negative_month_day_counts_back_from_the_end(): void
    {
        $this->assertSame(
            ['2026-02-28 16:00', '2026-03-31 16:00'],
            $this->expand('FREQ=MONTHLY;BYMONTHDAY=-1', '2026-02-01 16:00', '2026-02-01', '2026-04-01')
        );
    }

    public function test_a_yearly_rule_lands_on_the_same_date(): void
    {
        $this->assertSame(
            ['2026-03-01 08:00', '2027-03-01 08:00'],
            $this->expand('FREQ=YEARLY', '2026-03-01 08:00', '2026-01-01', '2027-12-31')
        );
    }

    public function test_count_stops_the_series(): void
    {
        $this->assertSame(
            ['2026-03-02 07:00', '2026-03-03 07:00', '2026-03-04 07:00'],
            $this->expand('FREQ=DAILY;COUNT=3', '2026-03-02 07:00', '2026-03-01', '2026-12-31')
        );
    }

    /**
     * COUNT counts from DTSTART, not from the window. Asking for a later window must not restart
     * the count and hand back three more — the series is spent.
     */
    public function test_a_window_after_the_count_is_spent_returns_nothing(): void
    {
        $this->assertSame(
            [],
            $this->expand('FREQ=DAILY;COUNT=3', '2026-03-02 07:00', '2026-04-01', '2026-04-30')
        );
    }

    public function test_until_stops_the_series_inclusively(): void
    {
        $this->assertSame(
            ['2026-03-02 07:00', '2026-03-09 07:00'],
            $this->expand('FREQ=WEEKLY;UNTIL=20260315T000000Z', '2026-03-02 07:00', '2026-03-01', '2026-12-31')
        );
    }

    /**
     * A daily 09:00 inspection stays a 09:00 inspection across a clock change. The rule is written
     * in local time, so the underlying instant moves by an hour and the wall clock does not — the
     * opposite of what storing UTC offsets naively would give.
     */
    public function test_the_time_of_day_survives_a_daylight_saving_change(): void
    {
        // US clocks go forward on Sunday 8 March 2026.
        $dates = $this->expand(
            'FREQ=DAILY', '2026-03-06 09:00', '2026-03-06', '2026-03-10 23:59', 'America/New_York'
        );

        $this->assertSame(
            ['2026-03-06 09:00', '2026-03-07 09:00', '2026-03-08 09:00', '2026-03-09 09:00', '2026-03-10 09:00'],
            $dates
        );
    }

    /**
     * A 02:30 occurrence on the morning the clocks jump 02:00 → 03:00 has no 02:30 to land on. It
     * shifts to 03:30 for that one day and returns to 02:30 afterwards — the occurrence happens
     * late rather than not at all, which is the right answer for a night-shift check that would
     * otherwise silently vanish once a year.
     */
    public function test_an_occurrence_at_a_clock_time_that_does_not_exist_shifts_forward(): void
    {
        $this->assertSame(
            ['2026-03-07 02:30', '2026-03-08 03:30', '2026-03-09 02:30'],
            $this->expand('FREQ=DAILY', '2026-03-07 02:30', '2026-03-07', '2026-03-09 23:59', 'America/New_York')
        );
    }

    public function test_a_window_before_the_start_returns_nothing(): void
    {
        $this->assertSame(
            [],
            $this->expand('FREQ=DAILY', '2026-03-02 07:00', '2026-01-01', '2026-02-01')
        );
    }

    public function test_a_rule_that_matches_nothing_terminates(): void
    {
        // 30 February, forever. The guard has to stop it rather than spin.
        $this->assertSame(
            [],
            $this->expand('FREQ=MONTHLY;BYMONTHDAY=30;BYMONTH=2', '2026-01-01 08:00', '2026-01-01', '2030-01-01')
        );
    }

    public function test_it_describes_itself_in_plain_language(): void
    {
        $this->assertSame(
            'Every month on the last Friday',
            Rrule::parse('FREQ=MONTHLY;BYDAY=-1FR')->describe()
        );

        $this->assertSame(
            'Every 2 weeks on the Tuesday, 6 times',
            Rrule::parse('FREQ=WEEKLY;INTERVAL=2;BYDAY=TU;COUNT=6')->describe()
        );
    }

    // ── What it refuses, and why it says so ──────────────────────────────────

    public function test_an_unsupported_part_is_named_rather_than_ignored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BYSETPOS is not supported');

        Rrule::parse('FREQ=MONTHLY;BYDAY=MO;BYSETPOS=-1');
    }

    public function test_count_and_until_together_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('COUNT or UNTIL, not both');

        Rrule::parse('FREQ=DAILY;COUNT=3;UNTIL=20260315T000000Z');
    }

    public function test_an_ordinal_on_a_weekly_rule_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only means something with FREQ=MONTHLY');

        Rrule::parse('FREQ=WEEKLY;BYDAY=2FR');
    }

    public function test_a_rule_with_no_frequency_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no FREQ');

        Rrule::parse('INTERVAL=2');
    }

    public function test_an_out_of_range_month_day_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range');

        Rrule::parse('FREQ=MONTHLY;BYMONTHDAY=32');
    }
}
