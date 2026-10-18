<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\AuditEntry;
use App\Modules\Operations\ProjectManagement\Models\Baseline;
use App\Modules\Operations\ProjectManagement\Models\CalendarException;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Operations\ProjectManagement\Services\BaselineService;
use App\Modules\Operations\ProjectManagement\Services\DependencyService;
use App\Modules\Operations\ProjectManagement\Services\SchedulingService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use InvalidArgumentException;

/**
 * The engine against a real project (PM-1230, PM-1233).
 *
 * The maths is proved in Tests\Unit\ProjectManagement\CpmEngineTest; this is about the wiring —
 * that the project's own calendar and holidays are used, that the answer is written back without
 * flooding the audit trail, and that a baseline is a copy rather than a pointer.
 */
class SchedulingServiceTest extends ProjectManagementTestCase
{
    private Project $project;

    private SchedulingService $scheduler;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['planned_start' => '2026-03-02 08:00']);
        $this->scheduler = app(SchedulingService::class);
        $this->tasks = app(TaskService::class);
    }

    private function task(string $name, int $days): Task
    {
        // Nine-hour days on the standard test calendar.
        return $this->tasks->create($this->project, ['name' => $name, 'duration_minutes' => $days * 9 * 60]);
    }

    private function link(Task $from, Task $to, string $type = 'fs', int $lag = 0): void
    {
        app(DependencyService::class)->link($from, $to, $type, $lag);
    }

    /**
     * Dates are stored in UTC (the app timezone) and read on site in the project's. Asserting on
     * the raw column would be asserting on storage rather than on what anyone sees.
     */
    private function onSite(?\Carbon\CarbonInterface $at): ?string
    {
        return $at?->setTimezone('Africa/Johannesburg')->format('Y-m-d H:i');
    }

    public function test_it_schedules_a_chain_across_the_projects_working_week(): void
    {
        $a = $this->task('Excavate', 3);
        $b = $this->task('Pour', 2);
        $this->link($a, $b);

        $this->scheduler->reschedule($this->project);

        // Mon 2 Mar 08:00 + three nine-hour days → Wed 4 Mar 17:00; the weekend pushes Pour's
        // finish from Friday into the following Monday.
        $this->assertSame('2026-03-04 17:00', $this->onSite($a->fresh()->early_finish));
        $this->assertSame('2026-03-05 08:00', $this->onSite($b->fresh()->early_start));
        $this->assertSame('2026-03-06 17:00', $this->onSite($b->fresh()->early_finish));
    }

    public function test_a_holiday_on_the_projects_calendar_pushes_the_dates_out(): void
    {
        CalendarException::create([
            'calendar_id'    => $this->project->calendar_id,
            'exception_date' => '2026-03-03',
            'is_working'     => false,
            'reason'         => 'Shutdown',
        ]);

        $a = $this->task('Excavate', 3);

        $this->scheduler->reschedule($this->project);

        // Tuesday is gone, so three days of work now reach Thursday.
        $this->assertSame('2026-03-05 17:00', $this->onSite($a->fresh()->early_finish));
    }

    public function test_the_critical_chain_is_marked_and_the_rest_carries_float(): void
    {
        $a = $this->task('Excavate', 5);
        $b = $this->task('Pour', 5);
        $c = $this->task('Order signage', 1);
        $this->link($a, $b);

        $this->scheduler->reschedule($this->project);

        $this->assertTrue($a->fresh()->is_critical);
        $this->assertTrue($b->fresh()->is_critical);
        $this->assertFalse($c->fresh()->is_critical, 'A one-day task beside a ten-day chain has float.');
        $this->assertGreaterThan(0, $c->fresh()->total_float_minutes);
    }

    /**
     * The reason the write-back bypasses Eloquent: a reschedule is one decision, not one per task.
     * Through models this would be an audit row for every task on the job, every time.
     */
    public function test_a_reschedule_writes_one_audit_row_not_one_per_task(): void
    {
        foreach (range(1, 8) as $i) {
            $this->task("Task {$i}", 2);
        }

        // The audit log cannot be cleared — it is append-only by trigger — so measure the delta.
        $before = AuditEntry::count();

        $this->scheduler->reschedule($this->project);

        $entries = AuditEntry::where('action', 'rescheduled')->get();

        $this->assertCount(1, $entries);
        $this->assertSame(8, $entries->first()->after['tasks']);
        $this->assertSame(1, AuditEntry::count() - $before, 'A reschedule is one decision, not one per task.');
    }

    public function test_a_summary_task_is_not_scheduled_as_work_in_its_own_right(): void
    {
        $parent = $this->task('Substructure', 0);
        $child = $this->tasks->create($this->project, ['name' => 'Excavate', 'duration_minutes' => 3 * 9 * 60], $parent);

        $result = $this->scheduler->reschedule($this->project);

        // Its dates come from the rollup, not the engine — scheduling both would count the work twice.
        $this->assertArrayNotHasKey((string) $parent->id, $result->tasks);
        $this->assertArrayHasKey((string) $child->id, $result->tasks);
    }

    public function test_a_what_if_leaves_the_saved_schedule_alone(): void
    {
        $a = $this->task('Excavate', 3);

        $this->scheduler->reschedule($this->project);
        $saved = $this->onSite($a->fresh()->early_finish);

        $result = $this->scheduler->calculate($this->project, [
            $a->uuid => ['duration_minutes' => 10 * 9 * 60],
        ]);

        $this->assertNotSame($saved, $this->onSite($result->tasks[(string) $a->id]->earlyFinish));
        $this->assertSame($saved, $this->onSite($a->fresh()->early_finish), 'The what-if was saved.');
    }

    public function test_a_conflict_is_reported_rather_than_thrown(): void
    {
        $a = $this->task('Excavate', 10);
        $b = $this->task('Pour', 2);
        $this->link($a, $b);

        $b->forceFill(['constraint_type' => 'mso', 'constraint_date' => '2026-03-05'])->save();

        $result = $this->scheduler->reschedule($this->project);

        $this->assertNotEmpty($result->conflicts);
        $this->assertStringContainsString('Excavate', $result->conflicts[0]->message);
    }

    // ── Baselines ────────────────────────────────────────────────────────────

    public function test_a_baseline_is_a_copy_that_does_not_move_with_the_plan(): void
    {
        $a = $this->task('Excavate', 3);
        $this->scheduler->reschedule($this->project);
        $this->tasks->update($a, ['planned_start' => '2026-03-02', 'planned_end' => '2026-03-04']);

        $baseline = app(BaselineService::class)->capture($this->project, 'Contract award');

        // The plan slips a week.
        $this->tasks->update($a, ['planned_end' => '2026-03-11']);

        $frozen = $baseline->tasks()->first();

        $this->assertSame('2026-03-04', $frozen->planned_end->toDateString());
        $this->assertSame('2026-03-11', $a->fresh()->planned_end->toDateString());
    }

    /**
     * Re-baselining is how a late project is made to look on time, so the second one has to say why.
     */
    public function test_re_baselining_without_a_reason_is_refused(): void
    {
        $this->task('Excavate', 3);
        $service = app(BaselineService::class);

        $service->capture($this->project, 'Contract award');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $service->capture($this->project, 'Rev B');
    }

    public function test_re_baselining_keeps_the_old_one_as_history(): void
    {
        $this->task('Excavate', 3);
        $service = app(BaselineService::class);

        $first = $service->capture($this->project, 'Contract award');
        $second = $service->capture($this->project, 'Rev B', 'Client added a level.');

        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->is_current);
        $this->assertSame(2, Baseline::count());
    }

    public function test_variance_reports_direction_not_just_size(): void
    {
        $late = $this->task('Excavate', 3);
        $early = $this->task('Fence', 1);

        $this->tasks->update($late, ['planned_end' => '2026-03-04 17:00']);
        $this->tasks->update($early, ['planned_end' => '2026-03-04 17:00']);

        $service = app(BaselineService::class);
        $service->capture($this->project, 'Contract award');

        $this->tasks->update($late, ['planned_end' => '2026-03-06 17:00']);   // two days late
        $this->tasks->update($early, ['planned_end' => '2026-03-03 17:00']);  // a day early

        $variance = collect($service->variance($this->project))->get('tasks');
        $byCode = collect($variance)->keyBy('code');

        $this->assertSame('late', $byCode[$late->code]['state']);
        $this->assertSame(2.0, $byCode[$late->code]['variance_days']);
        $this->assertSame('early', $byCode[$early->code]['state']);
        $this->assertSame(-1.0, $byCode[$early->code]['variance_days']);
    }

    public function test_work_dropped_after_baselining_is_still_reported(): void
    {
        $kept = $this->task('Excavate', 3);
        $dropped = $this->task('Landscaping', 2);

        $service = app(BaselineService::class);
        $service->capture($this->project, 'Contract award');

        $this->tasks->delete($dropped);

        $variance = $service->variance($this->project);
        $removed = collect($variance['tasks'])->firstWhere('state', 'removed');

        $this->assertSame(1, $variance['removed']);
        $this->assertSame('Landscaping', $removed['name'], 'Scope that disappears must not disappear quietly.');
    }

    public function test_work_added_after_baselining_is_flagged_as_new(): void
    {
        $this->task('Excavate', 3);

        $service = app(BaselineService::class);
        $service->capture($this->project, 'Contract award');

        $this->task('Extra level', 5);

        $variance = $service->variance($this->project);

        $this->assertSame(1, $variance['added']);
        $this->assertSame('Extra level', collect($variance['tasks'])->firstWhere('state', 'added')['name']);
    }

    public function test_a_project_with_no_baseline_says_so_rather_than_reporting_zero_variance(): void
    {
        $this->task('Excavate', 3);

        $variance = app(BaselineService::class)->variance($this->project);

        // Zero variance would read as "on plan", which is a different and unearned claim.
        $this->assertNull($variance['baseline']);
        $this->assertNull($variance['finish_variance_days']);
    }

    /**
     * A calendar deleted out from under a live project must not stop it being scheduled — the
     * standard week is a worse answer than the real one, and a far better answer than an exception
     * every time anybody opens the plan.
     */
    public function test_a_deleted_calendar_falls_back_to_the_standard_week(): void
    {
        WorkCalendar::find($this->project->calendar_id)->delete();

        $a = $this->task('Excavate', 1);

        $this->scheduler->reschedule($this->project);

        $this->assertNotNull($a->fresh()->early_finish);
    }
}
