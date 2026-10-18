<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Baseline;
use App\Modules\Operations\ProjectManagement\Models\BaselineTask;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Baselines and variance against them (PM-1233).
 *
 * A baseline is the schedule as it was agreed. Everything about this class exists to make that
 * claim survivable: the rows are copies rather than references, they are never edited, and taking a
 * new one requires a reason — because re-baselining is the standard way a late project is made to
 * look on time, and the record of who moved the goalposts is the part that matters afterwards.
 */
class BaselineService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SchedulingService $scheduling,
    ) {
    }

    /**
     * Freeze the current schedule.
     *
     * The previous current baseline stays, marked not-current: comparing against two points in the
     * project's history is a normal thing to want, and deleting it would make the second question
     * unanswerable.
     */
    public function capture(Project $project, string $name, ?string $reason = null): Baseline
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A baseline needs a name — "Rev C" or "Contract award".');
        }

        $existing = Baseline::where('project_id', $project->id)->current()->first();

        if ($existing && ! $reason) {
            throw new InvalidArgumentException(
                'This project already has a baseline. Re-baselining needs a reason, so the record shows why the dates moved.'
            );
        }

        return DB::transaction(function () use ($project, $name, $reason, $existing) {
            $existing?->forceFill(['is_current' => false])->save();

            $tasks = Task::where('project_id', $project->id)->real()->get();

            $baseline = Baseline::create([
                'project_id'     => $project->id,
                'name'           => $name,
                'reason'         => $reason,
                'captured_at'    => now(),
                'captured_by'    => Auth::id(),
                'planned_start'  => $tasks->min('planned_start'),
                'planned_finish' => $tasks->max('planned_end'),
                'task_count'     => $tasks->count(),
                'budget_amount'  => $tasks->sum(fn (Task $t) => (float) $t->budget_amount),
                'currency'       => $project->currency,
                'is_current'     => true,
            ]);

            // Inserted directly rather than through the model: a thousand-task baseline is a
            // thousand identical audit rows otherwise, and the capture itself is what was audited.
            $rows = $tasks->map(fn (Task $t) => [
                'company_id'       => $baseline->company_id,
                'baseline_id'      => $baseline->id,
                'task_id'          => $t->id,
                'task_code'        => $t->code,
                'name'             => $t->name,
                'planned_start'    => $t->planned_start,
                'planned_end'      => $t->planned_end,
                'duration_minutes' => (int) $t->duration_minutes,
                'is_critical'      => (bool) $t->is_critical,
                'is_milestone'     => (bool) $t->is_milestone,
                'budget_amount'    => $t->budget_amount,
                'created_at'       => now(),
                'updated_at'       => now(),
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                BaselineTask::insert($chunk);
            }

            $this->audit->recordAction($project, 'baselined', [
                'baseline' => $baseline->uuid,
                'name'     => $name,
                'reason'   => $reason,
                'tasks'    => count($rows),
                'replaced' => $existing?->uuid,
            ]);

            return $baseline;
        });
    }

    /**
     * How the project stands against its baseline.
     *
     * Reported per task and in total, in working days, with the sign kept: negative is early. An
     * absolute "variance" that hides the direction is worse than no number at all.
     *
     * @return array{
     *     baseline: Baseline|null,
     *     finish_variance_days: float|null,
     *     tasks: list<array<string,mixed>>,
     *     added: int, removed: int
     * }
     */
    public function variance(Project $project, ?Baseline $baseline = null): array
    {
        $baseline ??= Baseline::where('project_id', $project->id)->current()->first();

        if (! $baseline) {
            return ['baseline' => null, 'finish_variance_days' => null, 'tasks' => [], 'added' => 0, 'removed' => 0];
        }

        // Counted on the project's own calendar. Elapsed days would call a slip over a long weekend
        // four days when the crew lost one, and every figure downstream inherits that.
        $calendar = $this->scheduling->calendarFor($project);
        $perDay = max(1, $calendar->minutesPerWorkingDay());

        $days = fn (?\DateTimeInterface $was, ?\DateTimeInterface $now) => $was && $now
            ? round($calendar->between(
                \Carbon\CarbonImmutable::instance($was), \Carbon\CarbonImmutable::instance($now)
            ) / $perDay, 1)
            : null;

        $current = Task::where('project_id', $project->id)->real()->get()->keyBy('id');
        $frozen = BaselineTask::where('baseline_id', $baseline->id)->get();

        $rows = [];
        $seen = [];

        foreach ($frozen as $was) {
            $now = $was->task_id ? $current->get($was->task_id) : null;
            $seen[$was->task_id] = true;

            if (! $now) {
                // Promised, then dropped. Silence here is how scope quietly disappears.
                $rows[] = [
                    'code' => $was->task_code, 'name' => $was->name, 'state' => 'removed',
                    'baseline_end' => $was->planned_end?->toDateString(), 'current_end' => null,
                    'variance_days' => null,
                ];

                continue;
            }

            $variance = $days($was->planned_end, $now->planned_end);

            $rows[] = [
                'code' => $now->code, 'name' => $now->name,
                'state' => $variance === null ? 'undated' : ($variance > 0 ? 'late' : ($variance < 0 ? 'early' : 'on_plan')),
                'baseline_end' => $was->planned_end?->toDateString(),
                'current_end'  => $now->planned_end?->toDateString(),
                'variance_days' => $variance,
            ];
        }

        $added = $current->reject(fn (Task $t) => isset($seen[$t->id]))->count();

        foreach ($current->reject(fn (Task $t) => isset($seen[$t->id])) as $new) {
            $rows[] = [
                'code' => $new->code, 'name' => $new->name, 'state' => 'added',
                'baseline_end' => null, 'current_end' => $new->planned_end?->toDateString(),
                'variance_days' => null,
            ];
        }

        $currentFinish = $current->max('planned_end');

        return [
            'baseline' => $baseline,
            'finish_variance_days' => $days($baseline->planned_finish, $currentFinish),
            'tasks'   => $rows,
            'added'   => $added,
            'removed' => $frozen->filter(fn (BaselineTask $b) => ! $b->task_id || ! $current->has($b->task_id))->count(),
        ];
    }
}
