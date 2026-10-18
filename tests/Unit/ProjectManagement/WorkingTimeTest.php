<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Scheduling\WorkingTime;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Working-time arithmetic (PM-1230).
 *
 * Written before the implementation, because this is where scheduling quietly goes wrong: a task
 * that "takes two days" must land on the second working day, not 48 hours later, and a finish at
 * knock-off time must read as that evening rather than the next morning. Every wrong answer here
 * propagates through the whole critical path.
 *
 * No database and no application — a calendar in, dates out.
 */
class WorkingTimeTest extends TestCase
{
    private const WEEK = [
        'mon' => [['08:00', '17:00']],
        'tue' => [['08:00', '17:00']],
        'wed' => [['08:00', '17:00']],
        'thu' => [['08:00', '17:00']],
        'fri' => [['08:00', '17:00']],
        'sat' => [],
        'sun' => [],
    ];

    /** A site with a lunch break and a short Friday — the shape most real calendars have. */
    private const SPLIT_WEEK = [
        'mon' => [['07:00', '12:00'], ['13:00', '16:00']],
        'tue' => [['07:00', '12:00'], ['13:00', '16:00']],
        'wed' => [['07:00', '12:00'], ['13:00', '16:00']],
        'thu' => [['07:00', '12:00'], ['13:00', '16:00']],
        'fri' => [['07:00', '13:00']],
        'sat' => [],
        'sun' => [],
    ];

    private function calendar(array $week = self::WEEK, array $exceptions = [], string $tz = 'Africa/Johannesburg'): WorkingTime
    {
        return new WorkingTime($week, $tz, $exceptions);
    }

    private function at(string $when, string $tz = 'Africa/Johannesburg'): CarbonImmutable
    {
        return CarbonImmutable::parse($when, $tz);
    }

    private function fmt(CarbonImmutable $d): string
    {
        return $d->format('Y-m-d H:i');
    }

    // ── Is this working time? ────────────────────────────────────────────────

    public function test_it_knows_working_hours_from_the_rest(): void
    {
        $cal = $this->calendar();

        $this->assertTrue($cal->isWorking($this->at('2026-03-02 09:00')));   // Monday morning
        $this->assertFalse($cal->isWorking($this->at('2026-03-02 07:59')));  // before the shift
        $this->assertFalse($cal->isWorking($this->at('2026-03-07 09:00')));  // Saturday
        // 17:00 is the moment work stops, not a minute of work.
        $this->assertFalse($cal->isWorking($this->at('2026-03-02 17:00')));
    }

    public function test_a_lunch_break_is_not_working_time(): void
    {
        $cal = $this->calendar(self::SPLIT_WEEK);

        $this->assertTrue($cal->isWorking($this->at('2026-03-02 11:59')));
        $this->assertFalse($cal->isWorking($this->at('2026-03-02 12:30')));
        $this->assertTrue($cal->isWorking($this->at('2026-03-02 13:00')));
    }

    public function test_a_holiday_overrides_the_week(): void
    {
        // Human Rights Day, a Monday.
        $cal = $this->calendar(self::WEEK, ['2026-03-23' => ['is_working' => false]]);

        $this->assertFalse($cal->isWorking($this->at('2026-03-23 09:00')));
        $this->assertTrue($cal->isWorking($this->at('2026-03-24 09:00')));
    }

    public function test_a_catch_up_shift_makes_a_saturday_work(): void
    {
        $cal = $this->calendar(self::WEEK, [
            '2026-03-07' => ['is_working' => true, 'windows' => [['08:00', '13:00']]],
        ]);

        $this->assertTrue($cal->isWorking($this->at('2026-03-07 09:00')));
        $this->assertFalse($cal->isWorking($this->at('2026-03-07 14:00')));
    }

    // ── Snapping ─────────────────────────────────────────────────────────────

    public function test_a_non_working_moment_snaps_forward_to_the_next_shift(): void
    {
        $cal = $this->calendar();

        $this->assertSame('2026-03-02 08:00', $this->fmt($cal->next($this->at('2026-03-01 14:00')))); // Sunday
        $this->assertSame('2026-03-03 08:00', $this->fmt($cal->next($this->at('2026-03-02 18:00')))); // after hours
        // Already working: left exactly where it is.
        $this->assertSame('2026-03-02 09:30', $this->fmt($cal->next($this->at('2026-03-02 09:30'))));
    }

    public function test_a_non_working_moment_snaps_back_to_the_end_of_the_last_shift(): void
    {
        $cal = $this->calendar();

        $this->assertSame('2026-03-06 17:00', $this->fmt($cal->previous($this->at('2026-03-08 10:00')))); // Sunday
        $this->assertSame('2026-03-02 17:00', $this->fmt($cal->previous($this->at('2026-03-03 06:00'))));
    }

    // ── Adding duration ──────────────────────────────────────────────────────

    public function test_duration_is_measured_in_working_minutes_not_wall_clock(): void
    {
        $cal = $this->calendar();

        // Two hours from 16:00 Monday: one hour today, one tomorrow morning.
        $this->assertSame('2026-03-03 09:00', $this->fmt($cal->add($this->at('2026-03-02 16:00'), 120)));
    }

    public function test_a_finish_at_knock_off_stays_that_evening(): void
    {
        $cal = $this->calendar();

        // An hour from 16:00 ends at 17:00 — that day, not 08:00 the next. Rolling it forward would
        // report a task as finishing on a day nobody worked on it.
        $this->assertSame('2026-03-02 17:00', $this->fmt($cal->add($this->at('2026-03-02 16:00'), 60)));
    }

    public function test_a_days_work_lands_on_the_next_working_day(): void
    {
        $cal = $this->calendar();

        // 9-hour day; three days of work from Monday 08:00 ends Wednesday 17:00.
        $this->assertSame('2026-03-04 17:00', $this->fmt($cal->add($this->at('2026-03-02 08:00'), 27 * 60)));
    }

    public function test_work_steps_over_the_weekend(): void
    {
        $cal = $this->calendar();

        // Friday 16:00 + 2 hours: one hour Friday, one Monday morning.
        $this->assertSame('2026-03-09 09:00', $this->fmt($cal->add($this->at('2026-03-06 16:00'), 120)));
    }

    public function test_work_steps_over_a_holiday(): void
    {
        $cal = $this->calendar(self::WEEK, ['2026-03-03' => ['is_working' => false]]);

        // Monday 16:00 + 2 hours: Tuesday is a holiday, so the second hour is Wednesday.
        $this->assertSame('2026-03-04 09:00', $this->fmt($cal->add($this->at('2026-03-02 16:00'), 120)));
    }

    public function test_work_steps_over_a_lunch_break(): void
    {
        $cal = $this->calendar(self::SPLIT_WEEK);

        // 11:30 + 1 hour: half an hour before lunch, half an hour after.
        $this->assertSame('2026-03-02 13:30', $this->fmt($cal->add($this->at('2026-03-02 11:30'), 60)));
    }

    public function test_a_zero_duration_milestone_lands_on_the_next_working_moment(): void
    {
        $cal = $this->calendar();

        $this->assertSame('2026-03-02 08:00', $this->fmt($cal->add($this->at('2026-03-01 12:00'), 0)));
        // Already inside a shift: exactly where it was asked for.
        $this->assertSame('2026-03-02 10:00', $this->fmt($cal->add($this->at('2026-03-02 10:00'), 0)));
    }

    // ── Subtracting duration ─────────────────────────────────────────────────

    public function test_subtracting_walks_backwards_through_working_time(): void
    {
        $cal = $this->calendar();

        // Two hours back from Tuesday 09:00: an hour of Tuesday, an hour of Monday evening.
        $this->assertSame('2026-03-02 16:00', $this->fmt($cal->subtract($this->at('2026-03-03 09:00'), 120)));
    }

    public function test_subtracting_steps_back_over_the_weekend(): void
    {
        $cal = $this->calendar();

        $this->assertSame('2026-03-06 16:00', $this->fmt($cal->subtract($this->at('2026-03-09 09:00'), 120)));
    }

    /** add() and subtract() must be exact inverses, or the backward pass disagrees with the forward one. */
    public function test_adding_then_subtracting_returns_where_it_started(): void
    {
        $cal = $this->calendar(self::SPLIT_WEEK);
        $start = $this->at('2026-03-02 09:15');

        foreach ([30, 60, 240, 480, 1000, 5000] as $minutes) {
            $this->assertSame(
                $this->fmt($start),
                $this->fmt($cal->subtract($cal->add($start, $minutes), $minutes)),
                "add/subtract disagreed over {$minutes} minutes"
            );
        }
    }

    // ── Measuring ────────────────────────────────────────────────────────────

    public function test_it_counts_only_the_working_minutes_between_two_moments(): void
    {
        $cal = $this->calendar();

        // Monday 08:00 to Wednesday 08:00 is 48 hours of clock but 18 hours of work.
        $this->assertSame(18 * 60, $cal->between($this->at('2026-03-02 08:00'), $this->at('2026-03-04 08:00')));
        // A weekend on its own contains none.
        $this->assertSame(0, $cal->between($this->at('2026-03-07 00:00'), $this->at('2026-03-09 00:00')));
    }

    public function test_measuring_backwards_is_negative_rather_than_wrong(): void
    {
        $cal = $this->calendar();

        $this->assertSame(-540, $cal->between($this->at('2026-03-03 08:00'), $this->at('2026-03-02 08:00')));
    }

    // ── Clocks that move ─────────────────────────────────────────────────────

    /**
     * A calendar is written in local wall-clock time, so the working day is 08:00–17:00 on both
     * sides of a clock change. The day the clocks go forward is an hour shorter in real time, and a
     * schedule that ignored that would drift by an hour for every crew working through it.
     */
    public function test_a_daylight_saving_change_shortens_the_day_it_falls_on(): void
    {
        $cal = $this->calendar(self::WEEK, [], 'Europe/London');

        // UK clocks go forward on Sunday 29 March 2026, outside working hours.
        $monday = $this->at('2026-03-30 08:00', 'Europe/London');

        $this->assertSame('2026-03-30 17:00', $this->fmt($cal->add($monday, 9 * 60)));
        $this->assertSame(9 * 60, $cal->between($monday, $this->at('2026-03-30 17:00', 'Europe/London')));
    }

    public function test_a_clock_change_inside_the_working_day_is_counted_in_wall_clock_hours(): void
    {
        // A calendar whose shift spans the change: US clocks go forward 02:00 → 03:00 on 8 March.
        $cal = $this->calendar([
            'mon' => [['00:00', '08:00']], 'tue' => [['00:00', '08:00']], 'wed' => [['00:00', '08:00']],
            'thu' => [['00:00', '08:00']], 'fri' => [['00:00', '08:00']], 'sat' => [['00:00', '08:00']],
            'sun' => [['00:00', '08:00']],
        ], [], 'America/New_York');

        // The window reads 00:00–08:00 on the clock. In real time that Sunday is seven hours, and
        // the crew still goes home at 08:00 — the wall clock is what the site runs on.
        $this->assertSame(
            '2026-03-08 08:00',
            $this->fmt($cal->add($this->at('2026-03-08 00:00', 'America/New_York'), 8 * 60))
        );
    }

    // ── Degenerate calendars ─────────────────────────────────────────────────

    public function test_a_calendar_that_never_works_is_refused_rather_than_looping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no working time');

        new WorkingTime(
            ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []],
            'Africa/Johannesburg'
        );
    }

    public function test_a_window_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkingTime(['mon' => [['17:00', '08:00']]] + self::WEEK, 'Africa/Johannesburg');
    }

    public function test_a_long_shutdown_does_not_stall_the_arithmetic(): void
    {
        // Three weeks of December shutdown; work resumes in January.
        $exceptions = [];

        for ($d = 14; $d <= 31; $d++) {
            $exceptions[sprintf('2026-12-%02d', $d)] = ['is_working' => false];
        }

        for ($d = 1; $d <= 4; $d++) {
            $exceptions[sprintf('2027-01-%02d', $d)] = ['is_working' => false];
        }

        $cal = $this->calendar(self::WEEK, $exceptions);

        $this->assertSame('2027-01-05 09:00', $this->fmt($cal->add($this->at('2026-12-11 16:00'), 120)));
    }
}
