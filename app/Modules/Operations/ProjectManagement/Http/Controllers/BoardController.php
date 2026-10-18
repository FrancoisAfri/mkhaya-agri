<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use App\Modules\Operations\ProjectManagement\Services\SchedulingService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TaskStatusService;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskAssigneePresenter;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskDetailPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Three ways of looking at the same work: a board, a calendar and a timeline (PM-1250…1256).
 *
 * One payload serves all three. They differ in what they emphasise, not in what they know, and
 * fetching separately per view would guarantee the day a colleague swore the board and the calendar
 * disagreed.
 *
 * The timeline is deliberately the simple bar chart rather than a full Gantt: most people on a job
 * need to see when their work happens and what it waits on, and a planner's tool put in front of
 * everyone gets used by nobody.
 */
class BoardController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly SchedulingService $scheduling,
        private readonly TaskDetailPresenter $detail,
        private readonly TaskAssigneePresenter $assignees,
        private readonly TaskStatusService $steps,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $perDay = max(1, $this->scheduling->calendarFor($project)->minutesPerWorkingDay());

        // Archived cards are off the board unless somebody asks to see them.
        $showArchived = $request->boolean('archived');

        $tasks = Task::where('project_id', $project->id)
            ->real()
            ->onBoard($showArchived)
            ->with('wbsNode:id,uuid,name,wbs_code')
            ->orderByRaw('planned_start nulls last')
            ->orderBy('id')
            ->get();

        // One query for the whole board. Loading the relation per card is how twenty tasks become
        // forty queries and a real programme stops rendering.
        $assignees = $this->assignees->forTasks($tasks->pluck('id'));

        return Inertia::render('Modules/ProjectManagement/Projects/Board', [
            'project'  => [
                'id' => $project->uuid, 'code' => $project->code, 'name' => $project->name,
                'planned_start' => $project->planned_start?->toDateString(),
                'planned_end'   => $project->planned_end?->toDateString(),
            ],
            'tasks'    => $tasks->map(fn (Task $t) => [
                'id'           => $t->uuid,
                'code'         => $t->code,
                'name'         => $t->name,
                'status'       => $t->status,
                'priority'     => $t->priority,
                'start'        => $t->planned_start?->toDateString(),
                'end'          => $t->planned_end?->toDateString(),
                'progress'     => (float) $t->progress_pct,
                'is_critical'  => (bool) $t->is_critical,
                'is_milestone' => (bool) $t->is_milestone,
                'is_occurrence' => $t->occurrence_of_id !== null,
                'float_days'   => $t->total_float_minutes === null ? null : round($t->total_float_minutes / $perDay, 1),
                'group'        => $t->wbsNode?->uuid,
                'group_label'  => $t->wbsNode ? trim($t->wbsNode->wbs_code . ' ' . $t->wbsNode->name) : null,
                'assignees'    => $this->assignees->of($assignees, $t->id),
                // Which column to draw it in. A task on a project with its own steps sits in one
                // of those; everything else falls back to its plain status (T-0008).
                'column'       => $this->steps->columnOf($t),
            ])->all(),
            'groups'   => WbsNode::where('project_id', $project->id)
                ->orderBy('path')
                ->get(['uuid', 'name', 'wbs_code'])
                ->map(fn (WbsNode $n) => ['id' => $n->uuid, 'label' => trim($n->wbs_code . ' ' . $n->name)])
                ->all(),
            // The same drawer the work list opens, over the board this time. A card that cannot be
            // opened is a title and a date, and the conversation about the work then happens
            // somewhere the project cannot see it.
            'taskDetail' => $this->detail->forProject($project, $request->query('task')),
            'members' => ProjectMember::where('project_id', $project->id)->active()
                ->orderBy('display_name')
                ->get(['uuid', 'display_name', 'role_key'])
                ->map(fn (ProjectMember $m) => [
                    'id' => $m->uuid, 'name' => $m->display_name, 'role' => $m->role_key,
                ])->all(),
            // The project's own steps where it has defined any, the built-in five otherwise. Sent
            // whole — key, label, meaning and colour — because the board draws the label and the
            // rest of the module reads the meaning.
            'steps' => $this->steps->stepsFor($project),
            // How many tasks sit in each column, so removing one can say what it would move rather
            // than silently relocating somebody's work.
            'stepCounts' => $this->steps->counts($project),
            'categories' => Task::BOARD_STATUSES,
            'statuses' => Task::BOARD_STATUSES,
            'showArchived' => $showArchived,
            'archivedCount' => Task::where('project_id', $project->id)->real()
                ->where('status', Task::STATUS_ARCHIVED)->count(),
            'priorities' => Project::PRIORITIES,
            'can'      => [
                'editTasks' => $request->user()->can('update', $project),
                // Asked separately because it is a separate question: `storeTask` authorises against
                // Task::class, not against the project, so a user who may drag a card is not
                // automatically one who may create one. Offering an "add" box that 403s on submit is
                // worse than not offering it.
                'createTasks' => $request->user()->can('create', Task::class),
                // Changing the board's columns changes it for everybody on the project, so it is
                // the project-level grant rather than the task one.
                'manageSteps' => $request->user()->can('update', $project),
            ],
        ]);
    }

    /**
     * Replace the project's board columns.
     *
     * The whole set at once, not one column at a time: the order and the single default are
     * properties of the set, and applying a reorder as five separate edits would leave the board
     * briefly showing two defaults, or none.
     */
    public function updateSteps(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'steps'              => ['required', 'array', 'min:1', 'max:' . TaskStatusService::MAX_STEPS],
            'steps.*.id'         => ['nullable', 'uuid'],
            'steps.*.label'      => ['required', 'string', 'max:60'],
            // The meaning behind the name. Everything downstream of the board — the evidence
            // ledger, progress derivation, claims, the scheduler — reads this and never the label.
            'steps.*.category'   => ['required', Rule::in(Task::BOARD_STATUSES)],
            'steps.*.is_default' => ['nullable', 'boolean'],
            'steps.*.colour'     => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $this->steps->replace($project, $data['steps']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['steps' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.steps.saved'));
    }

    /**
     * Move a card between columns.
     *
     * Its own endpoint rather than the generic task update: dragging a card is a single-field
     * change made dozens of times in a sitting, and routing it through the full task validator
     * would demand a payload the board does not have.
     */
    public function moveCard(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $task);

        // A step key rather than a status: the board's columns are the project's own, and validating
        // against the built-in five would refuse every drag on a project that had named its own.
        $data = $request->validate(['status' => ['required', 'string', 'max:40']]);

        try {
            $resolved = $this->steps->resolve($project, $data['status']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->tasks->update($task, $resolved);

        return back();
    }
}
