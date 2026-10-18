<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskDependency;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\DependencyService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TaskStatusService;
use App\Modules\Operations\ProjectManagement\Services\WbsService;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskAssigneePresenter;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskDetailPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Modules\Operations\ProjectManagement\Services\TaskImportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A project's work: the breakdown structure and the tasks inside it (PM-1110, PM-1120, PM-1123).
 *
 * One page rather than two, because the two are read together — a task without its place in the
 * breakdown is a to-do item, and a breakdown with no tasks under it is an outline. Selecting a
 * branch filters the task list; that is the whole interaction.
 *
 * Every action authorises through TaskPolicy or ProjectPolicy, and the services enforce the rules
 * that must hold regardless of caller: subtree moves in one transaction, cycle rejection, parent
 * rollup.
 */
class WorkController extends Controller
{
    public function __construct(
        private readonly WbsService $wbs,
        private readonly TaskService $tasks,
        private readonly DependencyService $dependencies,
        private readonly TaskDetailPresenter $detail,
        private readonly AssignmentService $assignments,
        private readonly CollaborationService $collaboration,
        private readonly TaskAssigneePresenter $assignees,
        private readonly TaskStatusService $steps,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('Modules/ProjectManagement/Projects/Work', [
            'project'    => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],
            'tree'       => $this->wbs->tree($project),
            'tasks'      => $this->taskRows($project),
            // The drawer's contents, loaded only when one is open. The client asks for it with a
            // partial reload (?task=…), so opening a task does not re-send the whole task list and
            // posting a comment refreshes the conversation without touching the page around it.
            'taskDetail' => $this->detail->forProject($project, $request->query('task')),
            // Who the work can be given to. Without this the page could list tasks and never put
            // anyone on one — which is exactly where the module stood: the assign endpoint existed
            // and no screen in the application called it.
            'members'    => ProjectMember::where('project_id', $project->id)->active()
                ->orderBy('display_name')
                ->get(['uuid', 'display_name', 'role_key'])
                ->map(fn (ProjectMember $m) => [
                    'id' => $m->uuid, 'name' => $m->display_name, 'role' => $m->role_key,
                ])->all(),
            'nodeTypes'  => WbsNode::NODE_TYPES,
            // The project's own board columns, plus archived — unlike the board, this page is the
            // whole inventory of work, and an archived task opened here needs a status its own
            // dropdown can represent.
            'steps'      => [
                ...$this->steps->stepsFor($project),
                // Label left null for the browser to translate, like every other built-in column.
                [
                    'key' => Task::STATUS_ARCHIVED, 'label' => null,
                    'category' => Task::STATUS_ARCHIVED, 'is_default' => false, 'colour' => null,
                    'id' => null, 'custom' => false,
                ],
            ],
            'statuses'   => Task::STATUSES,
            'priorities' => Project::PRIORITIES,
            'linkTypes'  => TaskDependency::TYPES,
            'can'        => [
                'createTasks' => $request->user()->can('create', Task::class),
                'editTasks'   => $request->user()->can('update', $project),
                'schedule'    => $request->user()->can('schedule', Task::class),
            ],
        ]);
    }

    // ── Breakdown ────────────────────────────────────────────────────────────

    public function storeNode(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:190'],
            'node_type'    => ['nullable', Rule::in(WbsNode::NODE_TYPES)],
            'parent_id'    => ['nullable', 'uuid'],
            'is_milestone' => ['nullable', 'boolean'],
        ]);

        $parent = ! empty($data['parent_id'])
            ? WbsNode::where('project_id', $project->id)->where('uuid', $data['parent_id'])->firstOrFail()
            : null;

        $this->wbs->create($project, [
            'name'         => $data['name'],
            'node_type'    => $data['node_type'] ?? 'activity',
            'is_milestone' => $data['is_milestone'] ?? false,
            'created_by'   => Auth::id(),
        ], $parent);

        return back()->with('success', __('project_management.flash.wbs_created'));
    }

    /**
     * Move a branch. The service rejects a move that would put a node inside itself and names both
     * nodes; that message is surfaced as a validation error rather than a 500.
     */
    public function moveNode(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['parent_id' => ['nullable', 'uuid']]);

        $node = WbsNode::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
        $parent = ! empty($data['parent_id'])
            ? WbsNode::where('project_id', $project->id)->where('uuid', $data['parent_id'])->firstOrFail()
            : null;

        try {
            $this->wbs->move($node, $parent);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['parent_id' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.wbs_moved'));
    }

    public function destroyNode(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $node = WbsNode::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
        $this->wbs->delete($node);

        return back()->with('success', __('project_management.flash.wbs_deleted'));
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    /**
     * Create a task, and — where the request said so — put somebody on it and hang the paperwork
     * off it in the same breath.
     *
     * Both of those endpoints already existed and neither could be reached until the task did, so
     * raising a task somebody should start today meant creating it, finding it again, opening it,
     * assigning it, then opening the files tab. Five steps, and the honest outcome was a board full
     * of unassigned tasks with the photograph of the defect still sitting on somebody's phone.
     *
     * They stay separate concerns underneath: the same services, the same authorisation, the same
     * validation messages. What changed is only that one request can ask for all three.
     */
    public function storeTask(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $data = $this->taskRules($request);
        $extras = $this->companionRules($request);

        $parent = ! empty($data['parent_task_id'])
            ? Task::where('project_id', $project->id)->where('uuid', $data['parent_task_id'])->firstOrFail()
            : null;
        $node = ! empty($data['wbs_node_id'])
            ? WbsNode::where('project_id', $project->id)->where('uuid', $data['wbs_node_id'])->firstOrFail()
            : null;

        try {
            $data = $this->applyStep($project, $data, fillDefault: true);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $task = $this->tasks->create($project, [
            ...collect($data)->except(['parent_task_id', 'wbs_node_id'])->all(),
            'created_by' => Auth::id(),
        ], $parent, $node);

        /*
         * Assignment can legitimately refuse — the person is on leave, or has lost the ticket the
         * work needs. The task itself is sound and stays, and the reason comes back on the field
         * that caused it, so the fix is to pick somebody else rather than to type the task again.
         */
        if (! empty($extras['member_id'])) {
            $member = ProjectMember::where('project_id', $project->id)
                ->where('uuid', $extras['member_id'])->firstOrFail();

            try {
                $this->assignments->assign($task, $member);
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['member_id' => $e->getMessage()]);
            }
        }

        foreach ($request->file('attachments') ?? [] as $file) {
            try {
                $this->collaboration->attach($task, $file);
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['attachments' => $e->getMessage()]);
            }
        }

        return back()->with('success', __('project_management.flash.task_created'));
    }

    /**
     * What may ride along with a new task.
     *
     * Kept out of `taskRules` deliberately: these are not columns on the task, and letting them
     * through the shared validator would have `updateTask` silently accepting an assignee it then
     * discards.
     *
     * @return array<string, mixed>
     */
    private function companionRules(Request $request): array
    {
        return $request->validate([
            'member_id'     => ['nullable', 'uuid'],
            // Five, because this is the "raise it with the photo in front of you" case, not a
            // document library. The drawer takes the rest, one at a time, with room to say what
            // each one is.
            'attachments'   => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:' . (CollaborationService::MAX_ATTACHMENT_BYTES / 1024)],
        ]);
    }

    /**
     * Turn whatever the form called the status into the pair a task stores.
     *
     * A form sends one value — the column the user picked. The task keeps two: `status`, which is
     * the meaning every other part of the module reads, and `status_key`, which is merely which of
     * the project's own columns it sits in. Doing this in one place is what stops the two drifting
     * apart on one screen and not another.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyStep(Project $project, array $data, bool $fillDefault = false): array
    {
        if (($data['status'] ?? null) === null) {
            // On a create, silence means the project's own first column. On an edit it means "leave
            // the status alone", which is why the caller has to say which of the two it is.
            return $fillDefault ? array_merge($data, $this->steps->defaultFor($project)) : $data;
        }

        return array_merge($data, $this->steps->resolve($project, (string) $data['status']));
    }

    public function updateTask(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $task);

        try {
            $data = $this->applyStep($project, $this->taskRules($request));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->tasks->update($task, collect($data)->except(['parent_task_id', 'wbs_node_id'])->all());

        return back()->with('success', __('project_management.flash.task_updated'));
    }

    public function destroyTask(Project $project, string $uuid): RedirectResponse
    {
        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->authorize('delete', $task);

        $this->tasks->delete($task);

        return back()->with('success', __('project_management.flash.task_deleted'));
    }

    /** Set one field across a selection — the "mark these forty blocked" case. */
    public function bulkUpdateTasks(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'uuids'     => ['required', 'array', 'max:' . TaskService::BULK_LIMIT],
            'uuids.*'   => ['uuid'],
            'status'    => ['nullable', 'string', 'max:40'],
            'priority'  => ['nullable', Rule::in(Project::PRIORITIES)],
            // Putting one person on twenty tasks was previously twenty trips through a dialog that
            // did not exist on any screen, which is why nothing was ever assigned.
            'member_id' => ['nullable', 'uuid'],
        ]);

        try {
            $data = $this->applyStep($project, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $changes = collect($data)->only(['status', 'status_key', 'priority'])->filter()->all();

        // A move into a built-in column resolves status_key to null, and `filter()` drops nulls —
        // so it has to be put back, or a task dragged out of a custom column would keep pointing at
        // the one it left.
        if (array_key_exists('status', $changes)) {
            $changes['status_key'] = $data['status_key'] ?? null;
        }

        if ($changes === [] && empty($data['member_id'])) {
            return back()->withErrors(['status' => __('project_management.tasks.bulk_nothing_to_change')]);
        }

        $changed = $changes === [] ? count($data['uuids']) : $this->tasks->bulkUpdate($data['uuids'], $changes);

        if (! empty($data['member_id'])) {
            $assigned = $this->assignMany($project, $data['uuids'], $data['member_id']);

            if ($assigned instanceof RedirectResponse) {
                return $assigned;
            }
        }

        return back()->with('success', __('project_management.flash.tasks_bulk_updated', ['count' => $changed]));
    }

    /**
     * Put one person on many tasks.
     *
     * The refusals `AssignmentService` raises are per-person, not per-task — on leave, not qualified,
     * no longer active — so the first one is the answer for the whole batch and stopping there says
     * something true. Reporting "18 of 20 assigned" would leave the user to work out which two, and
     * the two it skipped would be the ones that mattered.
     *
     * @return int|RedirectResponse the number assigned, or the error to return
     */
    private function assignMany(Project $project, array $uuids, string $memberUuid): int|RedirectResponse
    {
        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $memberUuid)->firstOrFail();

        $tasks = Task::where('project_id', $project->id)->whereIn('uuid', $uuids)->get();

        foreach ($tasks as $task) {
            $this->authorize('update', $task);

            try {
                $this->assignments->assign($task, $member);
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['member_id' => $e->getMessage()]);
            }
        }

        return $tasks->count();
    }

    // ── Dependencies ─────────────────────────────────────────────────────────

    public function linkTasks(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('schedule', Task::class);

        $data = $request->validate([
            'predecessor_id' => ['required', 'uuid'],
            'successor_id'   => ['required', 'uuid', 'different:predecessor_id'],
            'dep_type'       => ['required', Rule::in(TaskDependency::TYPES)],
            'lag_minutes'    => ['nullable', 'integer', 'between:-100000,100000'],
        ]);

        $predecessor = Task::where('project_id', $project->id)->where('uuid', $data['predecessor_id'])->firstOrFail();
        $successor = Task::where('project_id', $project->id)->where('uuid', $data['successor_id'])->firstOrFail();

        try {
            $this->dependencies->link($predecessor, $successor, $data['dep_type'], (int) ($data['lag_minutes'] ?? 0));
        } catch (\InvalidArgumentException $e) {
            // The service names the loop; that sentence is what the user needs to see (Law 6).
            return back()->withErrors(['successor_id' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.dependency_created'));
    }

    public function unlinkTasks(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('schedule', Task::class);

        $dependency = TaskDependency::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
        $this->dependencies->unlink($dependency);

        return back()->with('success', __('project_management.flash.dependency_removed'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tasks with their links, flat. The client nests them — the payload stays small and the tree
     * shape is a rendering concern.
     *
     * @return list<array<string,mixed>>
     */
    private function taskRows(Project $project): array
    {
        $links = TaskDependency::where('project_id', $project->id)
            ->with(['predecessor:id,uuid,name,code'])
            ->get()
            ->groupBy('successor_id');

        $tasks = Task::where('project_id', $project->id)
            ->real()
            ->orderBy('id')
            ->get();

        // One query for the page, not one per row.
        $assignees = $this->assignees->forTasks($tasks->pluck('id'));

        return $tasks
            ->map(fn (Task $t) => [
                'id'            => $t->uuid,
                'code'          => $t->code,
                'name'          => $t->name,
                'status'        => $t->status,
                // Which of the project's own columns it sits in, where it sits in one (T-0008).
                'status_key'    => $t->status_key,
                'priority'      => $t->priority,
                'wbs_node_id'   => $t->wbsNode?->uuid,
                'parent_id'     => $t->parent?->uuid,
                'planned_start' => $t->planned_start?->toDateString(),
                'planned_end'   => $t->planned_end?->toDateString(),
                'progress_pct'  => (float) $t->progress_pct,
                'progress_source' => $t->progress_source,
                'is_milestone'  => (bool) $t->is_milestone,
                'payment_amount' => $t->payment_amount !== null ? (float) $t->payment_amount : null,
                'is_occurrence' => $t->occurrence_of_id !== null,
                'is_critical'   => (bool) $t->is_critical,
                'duration_minutes' => (int) $t->duration_minutes,
                'assignees'     => $this->assignees->of($assignees, $t->id),
                'predecessors'  => $links->get($t->id, collect())->map(fn (TaskDependency $d) => [
                    'id'   => $d->uuid,
                    'task' => $d->predecessor?->code . ' ' . $d->predecessor?->name,
                    'type' => $d->dep_type,
                    'lag'  => (int) $d->lag_minutes,
                ])->values()->all(),
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * The blank spreadsheet to fill in.
     *
     * Offered from the same dialog as the upload, because a template somebody has to go and find is
     * a template they will guess at instead — and a guessed column name is a whole file rejected.
     */
    public function tasksImportTemplate(Project $project, TaskImportService $importer): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('create', Task::class);

        return response($importer->templateCsv(), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . Str::slug($project->code ?: 'project') . '-tasks-template.csv"',
        ]);
    }

    /**
     * Create tasks from an uploaded spreadsheet.
     *
     * Every readable row becomes a not-started task; the rest come back named with the reason, so
     * the user corrects those lines rather than the whole file. Import is not undoable in one
     * action, which is why the row cap and the per-row validation both sit in the service.
     */
    public function tasksImport(Request $request, Project $project, TaskImportService $importer): RedirectResponse
    {
        $this->authorize('create', Task::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);

        $summary = $importer->import($request->file('file'), $project, Auth::id());

        if ($summary['imported'] === 0 && $summary['skipped'] === []) {
            return back()->withErrors(['file' => __('project_management.tasks.import.empty')]);
        }

        $message = trans_choice('project_management.tasks.import.created', $summary['imported'], ['count' => $summary['imported']]);

        if ($summary['truncated']) {
            $message .= ' ' . __('project_management.tasks.import.truncated', ['max' => TaskImportService::MAX_ROWS]);
        }

        return back()
            ->with('taskImport', $summary)
            ->with($summary['skipped'] === [] ? 'success' : 'warning', $summary['skipped'] === []
                ? $message
                : $message . ' ' . trans_choice('project_management.tasks.import.skipped', count($summary['skipped']), ['count' => count($summary['skipped'])]));
    }

    private function taskRules(Request $request): array
    {
        return $request->validate([
            'name'             => ['required', 'string', 'max:190'],
            'description'      => ['nullable', 'string'],
            // A step key, not necessarily a built-in status: the columns belong to the project
            // (T-0008). What it *means* is resolved against that project, below.
            'status'           => ['nullable', 'string', 'max:40'],
            'priority'         => ['nullable', Rule::in(Project::PRIORITIES)],
            'wbs_node_id'      => ['nullable', 'uuid'],
            'parent_task_id'   => ['nullable', 'uuid'],
            'planned_start'    => ['nullable', 'date'],
            'planned_end'      => ['nullable', 'date', 'after_or_equal:planned_start'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:5256000'],
            'estimated_hours'  => ['nullable', 'numeric', 'min:0'],
            // What "done" is measured in. Without it, progress can only ever be someone's opinion
            // (see DerivationRules — measured beats claimed).
            'planned_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'quantity_unit'    => ['nullable', 'string', 'max:20'],
            'is_milestone'     => ['nullable', 'boolean'],
            // Scheduling *input*; the engine's output is never accepted from a request.
            'schedule_mode'    => ['nullable', Rule::in(['auto', 'manual'])],
            'constraint_type'  => ['nullable', Rule::in(Task::CONSTRAINT_TYPES)],
            'constraint_date'  => ['nullable', 'date'],
            // Only meaningful on a milestone: what the contract pays when it is reached. The Site
            // page's claim dialog reads it, so without a way to set it here the column was
            // unreachable and milestone-based contracts could not be claimed at all.
            'payment_amount'   => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
    }
}
