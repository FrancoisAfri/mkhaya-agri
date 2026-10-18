<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Baseline;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\BaselineService;
use App\Modules\Operations\ProjectManagement\Services\SchedulingService;
use App\Modules\Operations\ProjectManagement\Support\Scheduling\ScheduleResult;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskAssigneePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The schedule: what the critical path says, and how it compares with what was agreed
 * (PM-1230, PM-1233, PM-1235).
 *
 * Rescheduling is behind its own grant. Recomputing the plan moves every date on the job and
 * therefore every commitment made against them — it is a different act from editing a task, and
 * TaskPolicy::schedule exists to say so.
 */
class ScheduleController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly BaselineService $baselines,
        private readonly TaskAssigneePresenter $assignees,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        // Read from what was last stored rather than recomputing on every page load: opening a
        // schedule must not silently change it, and a 20,000-task project would make the page slow
        // enough that nobody opens it twice.
        $variance = $this->baselines->variance($project);

        return Inertia::render('Modules/ProjectManagement/Projects/Schedule', [
            'project'   => [
                'id' => $project->uuid, 'code' => $project->code, 'name' => $project->name,
                'planned_start' => $project->planned_start?->toDateString(),
            ],
            'tasks'     => $this->rows($project),
            'baseline'  => $variance['baseline'] ? [
                'id'          => $variance['baseline']->uuid,
                'name'        => $variance['baseline']->name,
                'captured_at' => $variance['baseline']->captured_at?->toDateString(),
                'finish'      => $variance['baseline']->planned_finish?->toDateString(),
            ] : null,
            'variance'  => [
                'finish_days' => $variance['finish_variance_days'],
                'added'       => $variance['added'],
                'removed'     => $variance['removed'],
                'tasks'       => $variance['tasks'],
            ],
            'baselines' => Baseline::where('project_id', $project->id)->orderByDesc('captured_at')->get()
                ->map(fn (Baseline $b) => [
                    'id' => $b->uuid, 'name' => $b->name, 'reason' => $b->reason,
                    'captured_at' => $b->captured_at?->toDateString(), 'is_current' => (bool) $b->is_current,
                ])->all(),
            'lastRun'   => Task::where('project_id', $project->id)->max('scheduled_at'),
            'can'       => [
                'schedule' => $request->user()->can('schedule', Task::class),
                'manage'   => $request->user()->can('update', $project),
            ],
        ]);
    }

    /** Recompute. Conflicts come back as a warning on the page, not as a failure. */
    public function reschedule(Project $project): RedirectResponse
    {
        $this->authorize('schedule', Task::class);

        $result = $this->scheduling->reschedule($project);

        return back()->with('success', $this->summarise($result));
    }

    public function storeBaseline(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('schedule', Task::class);

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->baselines->capture($project, $data['name'], $data['reason'] ?? null);
        } catch (\InvalidArgumentException $e) {
            // "Re-baselining needs a reason" belongs on the reason field, where the person is.
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.baseline_captured'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(Project $project): array
    {
        $perDay = max(1, $this->scheduling->calendarFor($project)->minutesPerWorkingDay());

        $tasks = Task::where('project_id', $project->id)
            ->real()
            ->orderByRaw('early_start nulls last')
            ->orderBy('id')
            ->get();

        $assignees = $this->assignees->forTasks($tasks->pluck('id'));

        return $tasks
            ->map(fn (Task $t) => [
                'id'            => $t->uuid,
                'code'          => $t->code,
                'name'          => $t->name,
                'status'        => $t->status,
                'early_start'   => $t->early_start?->toDateTimeString(),
                'early_finish'  => $t->early_finish?->toDateTimeString(),
                'late_start'    => $t->late_start?->toDateTimeString(),
                'late_finish'   => $t->late_finish?->toDateTimeString(),
                // Days, not minutes: nobody plans in minutes, and the conversion depends on the
                // project's own calendar rather than an assumed eight-hour day.
                'float_days'    => $t->total_float_minutes === null ? null : round($t->total_float_minutes / $perDay, 1),
                'free_float_days' => $t->free_float_minutes === null ? null : round($t->free_float_minutes / $perDay, 1),
                'is_critical'   => (bool) $t->is_critical,
                'is_milestone'  => (bool) $t->is_milestone,
                'duration_days' => round(((int) $t->duration_minutes) / $perDay, 1),
                'scheduled'     => $t->scheduled_at !== null,
                // Who is meant to do it, beside when. A critical-path row nobody owns is the one
                // worth noticing, and the schedule could not say which those were.
                'assignees'     => $this->assignees->of($assignees, $t->id),
            ])
            ->all();
    }

    private function summarise(ScheduleResult $result): string
    {
        if ($result->conflicts !== []) {
            return __('project_management.flash.schedule_run_with_conflicts', [
                'count'    => count($result->conflicts),
                'conflict' => $result->conflicts[0]->message,
            ]);
        }

        return __('project_management.flash.schedule_run', [
            'finish' => $result->finish?->toFormattedDateString() ?? '—',
            'count'  => count($result->criticalPath),
        ]);
    }
}
