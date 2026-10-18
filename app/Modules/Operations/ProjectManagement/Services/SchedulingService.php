<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\CalendarException;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskDependency;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\CpmEngine;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleLink;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleResult;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleTask;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\WorkingTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Runs the critical-path engine against a real project and writes the answer back (PM-1230).
 *
 * Everything database-shaped lives here so the engine can stay pure (PM-ADR-005): this class loads
 * tasks, links and the calendar into value objects, calls the engine, and persists the result.
 *
 * The write-back deliberately bypasses Eloquent. A 20,000-task reschedule through models would fire
 * 20,000 audit rows and 20,000 sets of events for numbers no person typed — so the dates go back in
 * one statement per chunk, and the *reschedule* is what gets audited, once.
 */
class SchedulingService
{
    /** Rows per UPDATE … FROM (VALUES …). Large enough to be few round trips, small enough to plan. */
    private const CHUNK = 500;

    public function __construct(
        private readonly CpmEngine $engine,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Reschedule a project and store the result.
     *
     * @return ScheduleResult  including any conflicts, which are shown rather than thrown
     */
    public function reschedule(Project $project): ScheduleResult
    {
        $result = $this->calculate($project);

        DB::transaction(function () use ($project, $result) {
            $this->persist($project, $result);

            $this->audit->recordAction($project, 'rescheduled', [
                'tasks'     => count($result->tasks),
                'finish'    => $result->finish?->toDateTimeString(),
                'critical'  => count($result->criticalPath),
                'conflicts' => array_map(
                    fn ($c) => ['task' => $c->taskId, 'kind' => $c->kind, 'message' => $c->message],
                    $result->conflicts,
                ),
            ]);
        });

        return $result;
    }

    /**
     * Work out the schedule without saving it — a what-if.
     *
     * @param  array<string, array<string,mixed>>  $overrides  task uuid => changed fields
     */
    public function calculate(Project $project, array $overrides = []): ScheduleResult
    {
        $calendar = $this->calendarFor($project);

        return $this->engine->schedule(
            $this->tasksFor($project, $overrides),
            $this->linksFor($project),
            CarbonImmutable::parse($project->planned_start ?? now(), $calendar->timezone()),
            $calendar,
        );
    }

    /** The working calendar a project runs on, with its dated exceptions. */
    public function calendarFor(Project $project): WorkingTime
    {
        $calendar = $project->calendar_id
            ? WorkCalendar::find($project->calendar_id)
            : WorkCalendar::where('is_default', true)->first();

        $week = $calendar?->work_week ?: WorkCalendar::STANDARD_WEEK;
        $timezone = $calendar?->timezone ?: ($project->timezone ?: config('app.timezone'));

        $exceptions = [];

        if ($calendar) {
            foreach (CalendarException::where('calendar_id', $calendar->id)->get() as $exception) {
                $exceptions[$exception->exception_date->toDateString()] = [
                    'is_working' => (bool) $exception->is_working,
                    'windows'    => $exception->windows ?? [],
                ];
            }
        }

        return new WorkingTime($week, $timezone, $exceptions);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string,mixed>>  $overrides
     * @return list<ScheduleTask>
     */
    private function tasksFor(Project $project, array $overrides): array
    {
        $timezone = $this->calendarFor($project)->timezone();

        return Task::where('project_id', $project->id)
            ->real()
            // A summary task's dates come from its children (TaskService::rollUp), so scheduling it
            // as work in its own right would double-count every branch of the plan.
            ->whereDoesntHave('children')
            ->get()
            ->map(function (Task $task) use ($overrides, $timezone) {
                $o = $overrides[$task->uuid] ?? [];

                $at = fn (mixed $value) => $value === null ? null : CarbonImmutable::parse($value, $timezone);

                return new ScheduleTask(
                    id: (string) $task->id,
                    durationMinutes: (int) ($o['duration_minutes'] ?? $task->duration_minutes ?? 0),
                    scheduleMode: (string) ($o['schedule_mode'] ?? $task->schedule_mode ?? 'auto'),
                    constraintType: $o['constraint_type'] ?? $task->constraint_type,
                    constraintDate: $at($o['constraint_date'] ?? $task->constraint_date),
                    actualStart: $at($o['actual_start'] ?? $task->actual_start),
                    actualEnd: $at($o['actual_end'] ?? $task->actual_end),
                    manualStart: $at($o['planned_start'] ?? $task->planned_start),
                    name: $task->name,
                );
            })
            ->values()
            ->all();
    }

    /** @return list<ScheduleLink> */
    private function linksFor(Project $project): array
    {
        // Only links between tasks the engine was given: a dependency on a summary task, or on one
        // that has since been deleted, would otherwise abort the whole run.
        $scheduled = Task::where('project_id', $project->id)
            ->real()
            ->whereDoesntHave('children')
            ->pluck('id')
            ->flip();

        return TaskDependency::where('project_id', $project->id)
            ->get()
            ->filter(fn (TaskDependency $d) => $scheduled->has($d->predecessor_id) && $scheduled->has($d->successor_id))
            ->map(fn (TaskDependency $d) => new ScheduleLink(
                predecessorId: (string) $d->predecessor_id,
                successorId: (string) $d->successor_id,
                type: $d->dep_type,
                lagMinutes: (int) $d->lag_minutes,
            ))
            ->values()
            ->all();
    }

    /**
     * Write the engine's output back in bulk.
     *
     * One UPDATE … FROM (VALUES …) per chunk. The alternative — saving each model — turns a routine
     * reschedule into tens of thousands of writes and an audit trail nobody can read.
     */
    private function persist(Project $project, ScheduleResult $result): void
    {
        if ($result->tasks === []) {
            return;
        }

        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($result->tasks as $id => $dates) {
            $rows[] = [
                (int) $id,
                $dates->earlyStart->utc()->toDateTimeString(),
                $dates->earlyFinish->utc()->toDateTimeString(),
                $dates->lateStart->utc()->toDateTimeString(),
                $dates->lateFinish->utc()->toDateTimeString(),
                $dates->totalFloatMinutes,
                $dates->freeFloatMinutes,
                $dates->isCritical ? 'true' : 'false',
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $values = [];
            // Bound in statement order: the stamp appears in the SET clause, then the VALUES rows,
            // then the project guard in the WHERE.
            $bindings = [$now];

            foreach ($chunk as $row) {
                $values[] = '(?::bigint, ?::timestamp, ?::timestamp, ?::timestamp, ?::timestamp, ?::int, ?::int, ?::boolean)';
                array_push($bindings, ...$row);
            }

            $bindings[] = $project->id;

            DB::update(
                'UPDATE pm_tasks SET
                     early_start = v.early_start,
                     early_finish = v.early_finish,
                     late_start = v.late_start,
                     late_finish = v.late_finish,
                     total_float_minutes = v.total_float,
                     free_float_minutes = v.free_float,
                     is_critical = v.is_critical,
                     scheduled_at = ?::timestamp
                   FROM (VALUES ' . implode(',', $values) . ')
                     AS v(id, early_start, early_finish, late_start, late_finish, total_float, free_float, is_critical)
                  WHERE pm_tasks.id = v.id AND pm_tasks.project_id = ?',
                // The project guard is what stops a task id belonging to another project being
                // written, even if one somehow reached the engine.
                $bindings
            );
        }
    }
}
