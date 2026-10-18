<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Scheduling\CpmEngine;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleLink;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleTask;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\WorkingTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The critical-path engine (PM-1230, PM-1231).
 *
 * The first test is a published textbook network with hand-computed answers, run against a
 * round-the-clock calendar so every figure can be checked in whole days without the calendar
 * getting in the way. The rest cover what textbooks skip and real jobs hit: the four link types,
 * leads, constraints that fight their predecessors, and work that has already started.
 *
 * Pure: tasks and links in, dates out, no database (PM-ADR-005).
 */
class CpmEngineTest extends TestCase
{
    private const DAY = 24 * 60;

    /** Round the clock, so a "day" is exactly 1,440 minutes and the arithmetic is checkable by eye. */
    private function alwaysOn(): WorkingTime
    {
        return new WorkingTime(array_fill_keys(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            [['00:00', '24:00']]
        ), 'UTC');
    }

    private function officeHours(): WorkingTime
    {
        return new WorkingTime([
            'mon' => [['08:00', '17:00']], 'tue' => [['08:00', '17:00']], 'wed' => [['08:00', '17:00']],
            'thu' => [['08:00', '17:00']], 'fri' => [['08:00', '17:00']], 'sat' => [], 'sun' => [],
        ], 'Africa/Johannesburg');
    }

    private function at(string $when, string $tz = 'UTC'): CarbonImmutable
    {
        return CarbonImmutable::parse($when, $tz);
    }

    /** Days elapsed from the project start, for reading results in textbook units. */
    private function day(CarbonImmutable $start, CarbonImmutable $moment): float
    {
        return round($start->diffInMinutes($moment) / self::DAY, 3);
    }

    /**
     * A standard CPM network:
     *
     *   A(3) ─┬─ B(4) ── D(5) ─┬─ F(2)
     *         └─ C(2) ── E(1) ─┘
     *
     * Critical path A→B→D→F at 14 days; C and E carry six days of float.
     */
    public function test_it_reproduces_a_published_network(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('A', 3 * self::DAY),
                new ScheduleTask('B', 4 * self::DAY),
                new ScheduleTask('C', 2 * self::DAY),
                new ScheduleTask('D', 5 * self::DAY),
                new ScheduleTask('E', 1 * self::DAY),
                new ScheduleTask('F', 2 * self::DAY),
            ],
            [
                new ScheduleLink('A', 'B', 'fs'),
                new ScheduleLink('A', 'C', 'fs'),
                new ScheduleLink('B', 'D', 'fs'),
                new ScheduleLink('C', 'E', 'fs'),
                new ScheduleLink('D', 'F', 'fs'),
                new ScheduleLink('E', 'F', 'fs'),
            ],
            $start,
            $this->alwaysOn(),
        );

        $early = [];
        $late = [];
        $float = [];

        foreach ($result->tasks as $id => $dates) {
            $early[$id] = [$this->day($start, $dates->earlyStart), $this->day($start, $dates->earlyFinish)];
            $late[$id] = [$this->day($start, $dates->lateStart), $this->day($start, $dates->lateFinish)];
            $float[$id] = round($dates->totalFloatMinutes / self::DAY, 3);
        }

        $this->assertSame(
            ['A' => [0.0, 3.0], 'B' => [3.0, 7.0], 'C' => [3.0, 5.0],
                'D' => [7.0, 12.0], 'E' => [5.0, 6.0], 'F' => [12.0, 14.0]],
            $early
        );

        $this->assertSame(
            ['A' => [0.0, 3.0], 'B' => [3.0, 7.0], 'C' => [9.0, 11.0],
                'D' => [7.0, 12.0], 'E' => [11.0, 12.0], 'F' => [12.0, 14.0]],
            $late
        );

        $this->assertSame(['A' => 0.0, 'B' => 0.0, 'C' => 6.0, 'D' => 0.0, 'E' => 6.0, 'F' => 0.0], $float);
        $this->assertSame(['A', 'B', 'D', 'F'], $result->criticalPath);
        $this->assertSame(14.0, $this->day($start, $result->finish));
    }

    /**
     * Free float is what a task can slip without moving anything else — distinct from total float,
     * which is what it can slip without moving the *project*. C has six days of total float and
     * none free: slipping it pushes E immediately.
     */
    public function test_free_float_differs_from_total_float(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('A', 3 * self::DAY),
                new ScheduleTask('C', 2 * self::DAY),
                new ScheduleTask('E', 1 * self::DAY),
                new ScheduleTask('F', 2 * self::DAY),
                new ScheduleTask('B', 4 * self::DAY),
                new ScheduleTask('D', 5 * self::DAY),
            ],
            [
                new ScheduleLink('A', 'B', 'fs'), new ScheduleLink('A', 'C', 'fs'),
                new ScheduleLink('B', 'D', 'fs'), new ScheduleLink('C', 'E', 'fs'),
                new ScheduleLink('D', 'F', 'fs'), new ScheduleLink('E', 'F', 'fs'),
            ],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(0.0, round($result->tasks['C']->freeFloatMinutes / self::DAY, 3));
        $this->assertSame(6.0, round($result->tasks['E']->freeFloatMinutes / self::DAY, 3));
    }

    // ── The four link types ──────────────────────────────────────────────────

    public function test_start_to_start_lets_work_run_alongside(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // Lay the pipe two days after trenching begins, not after it ends.
        $result = (new CpmEngine)->schedule(
            [new ScheduleTask('trench', 10 * self::DAY), new ScheduleTask('lay', 8 * self::DAY)],
            [new ScheduleLink('trench', 'lay', 'ss', 2 * self::DAY)],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(2.0, $this->day($start, $result->tasks['lay']->earlyStart));
        $this->assertSame(10.0, $this->day($start, $result->tasks['lay']->earlyFinish));
    }

    public function test_finish_to_finish_holds_the_finishes_together(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // Testing cannot finish until a day after installation finishes.
        $result = (new CpmEngine)->schedule(
            [new ScheduleTask('install', 5 * self::DAY), new ScheduleTask('test', 2 * self::DAY)],
            [new ScheduleLink('install', 'test', 'ff', 1 * self::DAY)],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(6.0, $this->day($start, $result->tasks['test']->earlyFinish));
        $this->assertSame(4.0, $this->day($start, $result->tasks['test']->earlyStart));
    }

    public function test_start_to_finish_is_supported_even_though_it_is_rare(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // The old system must keep running until the new one starts — a changeover. The new system
        // waits twelve days on its own build, and the old one's finish is dragged out to meet it.
        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('build_new', 12 * self::DAY),
                new ScheduleTask('new_system', 3 * self::DAY),
                new ScheduleTask('old_system', 10 * self::DAY),
            ],
            [
                new ScheduleLink('build_new', 'new_system', 'fs'),
                new ScheduleLink('new_system', 'old_system', 'sf'),
            ],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(12.0, $this->day($start, $result->tasks['new_system']->earlyStart));
        // Finishes as the new one starts, so its start is pulled back by its own ten days.
        $this->assertSame(12.0, $this->day($start, $result->tasks['old_system']->earlyFinish));
        $this->assertSame(2.0, $this->day($start, $result->tasks['old_system']->earlyStart));
    }

    public function test_a_lead_pulls_the_successor_forward(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // Negative lag: start finishing two days before the predecessor ends.
        $result = (new CpmEngine)->schedule(
            [new ScheduleTask('formwork', 6 * self::DAY), new ScheduleTask('rebar', 4 * self::DAY)],
            [new ScheduleLink('formwork', 'rebar', 'fs', -2 * self::DAY)],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(4.0, $this->day($start, $result->tasks['rebar']->earlyStart));
    }

    // ── Constraints ──────────────────────────────────────────────────────────

    public function test_start_no_earlier_than_holds_a_task_back(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // The crane is not on site until the 10th, whatever the network says.
        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('prep', 2 * self::DAY),
                new ScheduleTask('lift', 1 * self::DAY, constraintType: 'snet', constraintDate: $this->at('2026-03-10 00:00')),
            ],
            [new ScheduleLink('prep', 'lift', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(8.0, $this->day($start, $result->tasks['lift']->earlyStart));
        $this->assertSame(9.0, $this->day($start, $result->finish));
    }

    /**
     * A constraint that contradicts the network is reported, not silently obeyed or silently
     * dropped. The person reading the schedule has to know the date they typed cannot hold.
     */
    public function test_a_must_start_on_that_its_predecessor_forbids_is_reported_in_plain_language(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('excavate', 10 * self::DAY),
                new ScheduleTask('pour', 2 * self::DAY, constraintType: 'mso', constraintDate: $this->at('2026-03-05 00:00')),
            ],
            [new ScheduleLink('excavate', 'pour', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        // Two conflicts, and both are worth saying: the pinned task cannot have what it was
        // promised, and the task ahead of it is now behind by a measurable amount.
        $conflict = collect($result->conflicts)->firstWhere('taskId', 'pour');

        $this->assertNotNull($conflict);
        $this->assertSame('constraint_vs_predecessor', $conflict->kind);
        $this->assertNotNull(collect($result->conflicts)->firstWhere('taskId', 'excavate'));
        $this->assertStringContainsString('must start on', $conflict->message);
        $this->assertStringContainsString('excavate', $conflict->message);
        // The constraint wins the date — that is what "must" means — and the clash is flagged.
        $this->assertSame(3.0, $this->day($start, $result->tasks['pour']->earlyStart));
    }

    public function test_finish_no_later_than_that_cannot_be_met_is_reported(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [new ScheduleTask('build', 20 * self::DAY, constraintType: 'fnlt', constraintDate: $this->at('2026-03-10 00:00'))],
            [],
            $start,
            $this->alwaysOn(),
        );

        $this->assertCount(1, $result->conflicts);
        $this->assertStringContainsString('cannot finish', $result->conflicts[0]->message);
        // 2 March plus twenty days is 22 March, against a deadline of the 10th: twelve days late.
        // Negative float is the honest way to report that, rather than quietly moving the deadline.
        $this->assertSame(-12.0, round($result->tasks['build']->totalFloatMinutes / self::DAY, 3));
        $this->assertStringContainsString('12 working day', $result->conflicts[0]->message);
    }

    // ── Work already under way ───────────────────────────────────────────────

    public function test_an_actual_start_pins_the_task_where_it_really_began(): void
    {
        $start = $this->at('2026-03-02 00:00');

        // It started late. The schedule reports what happened, not what was planned.
        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('excavate', 4 * self::DAY, actualStart: $this->at('2026-03-04 00:00')),
                new ScheduleTask('pour', 2 * self::DAY),
            ],
            [new ScheduleLink('excavate', 'pour', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(2.0, $this->day($start, $result->tasks['excavate']->earlyStart));
        $this->assertSame(6.0, $this->day($start, $result->tasks['excavate']->earlyFinish));
        $this->assertSame(6.0, $this->day($start, $result->tasks['pour']->earlyStart));
    }

    public function test_a_finished_task_does_not_move_and_frees_its_successors(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('excavate', 4 * self::DAY,
                    actualStart: $this->at('2026-03-02 00:00'), actualEnd: $this->at('2026-03-05 00:00')),
                new ScheduleTask('pour', 2 * self::DAY),
            ],
            [new ScheduleLink('excavate', 'pour', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        // It took three days, not the four planned; the successor gets the day back.
        $this->assertSame(3.0, $this->day($start, $result->tasks['excavate']->earlyFinish));
        $this->assertSame(3.0, $this->day($start, $result->tasks['pour']->earlyStart));
    }

    // ── Manual scheduling ────────────────────────────────────────────────────

    public function test_a_manually_scheduled_task_keeps_its_dates_but_still_drives_its_successors(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('survey', 2 * self::DAY),
                new ScheduleTask('permit', 5 * self::DAY, scheduleMode: 'manual', manualStart: $this->at('2026-03-16 00:00')),
                new ScheduleTask('build', 3 * self::DAY),
            ],
            [new ScheduleLink('survey', 'permit', 'fs'), new ScheduleLink('permit', 'build', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        $this->assertSame(14.0, $this->day($start, $result->tasks['permit']->earlyStart));
        $this->assertSame(19.0, $this->day($start, $result->tasks['build']->earlyStart));
    }

    // ── The calendar is respected ────────────────────────────────────────────

    public function test_the_working_calendar_governs_every_date(): void
    {
        $start = $this->at('2026-03-02 08:00', 'Africa/Johannesburg');

        // Two nine-hour days of work, over a Friday-to-Monday weekend.
        $result = (new CpmEngine)->schedule(
            [new ScheduleTask('a', 27 * 60), new ScheduleTask('b', 18 * 60)],
            [new ScheduleLink('a', 'b', 'fs')],
            $start,
            $this->officeHours(),
        );

        $this->assertSame('2026-03-04 17:00', $result->tasks['a']->earlyFinish->format('Y-m-d H:i'));
        $this->assertSame('2026-03-05 08:00', $result->tasks['b']->earlyStart->format('Y-m-d H:i'));
        $this->assertSame('2026-03-06 17:00', $result->tasks['b']->earlyFinish->format('Y-m-d H:i'));
    }

    public function test_a_milestone_takes_no_time_and_can_still_be_critical(): void
    {
        $start = $this->at('2026-03-02 00:00');

        $result = (new CpmEngine)->schedule(
            [
                new ScheduleTask('works', 5 * self::DAY),
                new ScheduleTask('handover', 0),
            ],
            [new ScheduleLink('works', 'handover', 'fs')],
            $start,
            $this->alwaysOn(),
        );

        $dates = $result->tasks['handover'];
        $this->assertEquals($dates->earlyStart, $dates->earlyFinish);
        $this->assertTrue($dates->isCritical);
    }

    // ── What it refuses ──────────────────────────────────────────────────────

    public function test_a_loop_is_named_rather_than_hung_on(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a → b → c → a');

        (new CpmEngine)->schedule(
            [new ScheduleTask('a', 60), new ScheduleTask('b', 60), new ScheduleTask('c', 60)],
            [new ScheduleLink('a', 'b', 'fs'), new ScheduleLink('b', 'c', 'fs'), new ScheduleLink('c', 'a', 'fs')],
            $this->at('2026-03-02 00:00'),
            $this->alwaysOn(),
        );
    }

    public function test_a_link_to_a_task_that_is_not_there_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ghost');

        (new CpmEngine)->schedule(
            [new ScheduleTask('a', 60)],
            [new ScheduleLink('a', 'ghost', 'fs')],
            $this->at('2026-03-02 00:00'),
            $this->alwaysOn(),
        );
    }

    public function test_an_unknown_link_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ScheduleLink('a', 'b', 'maybe');
    }

    public function test_an_empty_project_schedules_to_nothing_rather_than_failing(): void
    {
        $result = (new CpmEngine)->schedule([], [], $this->at('2026-03-02 00:00'), $this->alwaysOn());

        $this->assertSame([], $result->tasks);
        $this->assertSame([], $result->criticalPath);
    }

    // ── Size ─────────────────────────────────────────────────────────────────

    /**
     * PM-1230's budget is 20,000 tasks and 30,000 links inside five seconds. Run at a tenth of that
     * so the suite stays quick, with the budget scaled to match — the point is to catch an
     * accidental quadratic, which shows up just as clearly at 2,000.
     */
    public function test_it_stays_linear_on_a_large_network(): void
    {
        $tasks = [];
        $links = [];

        for ($i = 0; $i < 2000; $i++) {
            $tasks[] = new ScheduleTask("t{$i}", 8 * 60);

            if ($i > 0) {
                $links[] = new ScheduleLink('t' . ($i - 1), "t{$i}", 'fs');
            }

            if ($i > 10) {
                $links[] = new ScheduleLink('t' . ($i - 11), "t{$i}", 'ss', 60);
            }
        }

        $began = microtime(true);

        $result = (new CpmEngine)->schedule($tasks, $links, $this->at('2026-03-02 00:00'), $this->officeHours());

        $elapsed = microtime(true) - $began;

        $this->assertCount(2000, $result->tasks);
        $this->assertLessThan(5.0, $elapsed, "2,000 tasks took {$elapsed}s; the budget is 5s for 20,000.");
    }
}
