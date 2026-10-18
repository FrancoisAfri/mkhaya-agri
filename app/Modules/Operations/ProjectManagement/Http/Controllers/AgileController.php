<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\Epic;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Release;
use App\Modules\Operations\ProjectManagement\Models\Sprint;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\AgileService;
use App\Modules\Operations\ProjectManagement\Support\Presenters\TaskAssigneePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Sprints, backlog, epics and releases (§24).
 *
 * Stories are tasks, so this controller only ever arranges them — nothing here duplicates task
 * creation, assignment or status, and the Kanban board works on an agile project unchanged.
 */
class AgileController extends Controller
{
    public function __construct(
        private readonly AgileService $agile,
        private readonly TaskAssigneePresenter $assignees,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $active = Sprint::where('project_id', $project->id)->active()->first();

        $sprintStories = $active
            ? Task::where('sprint_id', $active->id)->orderBy('backlog_rank')->get()
            : collect();

        // The backlog is everything not in a sprint and not finished. Ordered by rank, with
        // unranked items last rather than first — an unranked item is one nobody has prioritised,
        // and putting it at the top says the opposite.
        $backlog = Task::where('project_id', $project->id)
            ->whereNull('sprint_id')
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderByRaw('backlog_rank is null, backlog_rank')
            ->limit(500)->get();

        /*
         * Both lists in one lookup.
         *
         * This board asked each Task for an `assignee_display` attribute that has never existed on
         * the model, so every story on every sprint has rendered with no owner since the screen was
         * written — silently, because a missing attribute is null rather than an error.
         */
        $assignees = $this->assignees->forTasks($sprintStories->pluck('id')->merge($backlog->pluck('id')));

        $story = fn (Task $t) => [
            'id'      => $t->uuid,
            'name'    => $t->name,
            'status'  => $t->status,
            'points'  => $t->story_points !== null ? (float) $t->story_points : null,
            'epic_id' => $t->epic_id,
            'assignees' => $this->assignees->of($assignees, $t->id),
            'rank'    => $t->backlog_rank,
        ];

        return Inertia::render('Modules/ProjectManagement/Projects/Agile', [
            'project' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name],

            'sprints' => Sprint::where('project_id', $project->id)->orderByDesc('number')->get()
                ->map(fn (Sprint $s) => [
                    'id'        => $s->uuid,
                    'name'      => $s->name,
                    'number'    => $s->number,
                    'goal'      => $s->goal,
                    'starts_on' => $s->starts_on->toDateString(),
                    'ends_on'   => $s->ends_on->toDateString(),
                    'status'    => $s->status,
                    'committed' => $s->committed_points !== null ? (float) $s->committed_points : null,
                    'completed' => $s->completed_points !== null ? (float) $s->completed_points : null,
                    'retrospective' => $s->retrospective,
                ])->all(),

            'active' => $active ? [
                'id'        => $active->uuid,
                'name'      => $active->name,
                'goal'      => $active->goal,
                'day'       => $active->dayOf(),
                'days'      => $active->totalDays(),
                'committed' => (float) $active->committed_points,
                'burndown'  => $this->agile->burndown($active),
                'stories'   => $sprintStories->map($story)->all(),
            ] : null,

            'backlog' => $backlog->map($story)->all(),

            'epics' => Epic::where('project_id', $project->id)->orderBy('key')->get()
                ->map(fn (Epic $e) => [
                    'id'     => $e->uuid,
                    'key'    => $e->key,
                    'name'   => $e->name,
                    'colour' => $e->colour,
                    'status' => $e->status,
                    'target' => optional($e->target_date)->toDateString(),
                    'stories' => $e->stories()->count(),
                    'done'    => $e->stories()->where('status', 'done')->count(),
                ])->all(),

            'releases' => Release::where('project_id', $project->id)->orderByDesc('id')->get()
                ->map(fn (Release $r) => [
                    'id'          => $r->uuid,
                    'version'     => $r->version,
                    'name'        => $r->name,
                    'status'      => $r->status,
                    'target'      => optional($r->target_date)->toDateString(),
                    'released_at' => optional($r->released_at)->toDateString(),
                    'revision'    => $r->revision,
                    'environment' => $r->environment,
                    'notes'       => $r->notes,
                    'items'       => $r->items()->count(),
                ])->all(),

            // Null until a team has closed a sprint. Showing zero would invite somebody to plan
            // against it.
            'velocity' => $this->agile->velocity($project),
            'members'  => ProjectMember::where('project_id', $project->id)->active()->orderBy('display_name')
                ->get(['user_id', 'display_name'])
                ->map(fn (ProjectMember $m) => ['id' => $m->user_id, 'name' => $m->display_name])->all(),
            'can' => ['manage' => $request->user()->can('update', $project)],
        ]);
    }

    // ── Sprints ─────────────────────────────────────────────────────────────

    public function storeSprint(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->agile->createSprint($project, $request->validate([
            'name'      => ['required', 'string', 'max:190'],
            'goal'      => ['nullable', 'string', 'max:1000'],
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['required', 'date', 'after:starts_on'],
        ]));

        return back()->with('success', __('project_management.flash.sprint_created'));
    }

    public function startSprint(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        try {
            $this->agile->startSprint($this->sprint($project, $uuid));
        } catch (RuntimeException $e) {
            return back()->withErrors(['sprint' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.sprint_started'));
    }

    public function closeSprint(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['retrospective' => ['nullable', 'string', 'max:5000']]);

        try {
            $this->agile->closeSprint($this->sprint($project, $uuid), $data['retrospective'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['sprint' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.sprint_closed'));
    }

    // ── Backlog ─────────────────────────────────────────────────────────────

    /** Move a story into or out of the active sprint, and set its points. */
    public function updateStory(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'sprint_id'    => ['nullable', 'uuid'],
            'epic_id'      => ['nullable', 'uuid'],
            'release_id'   => ['nullable', 'uuid'],
            'story_points' => ['nullable', 'numeric', 'between:0,999'],
        ]);

        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $task->update([
            'sprint_id'    => $this->idOf(Sprint::class, $project, $data['sprint_id'] ?? null),
            'epic_id'      => $this->idOf(Epic::class, $project, $data['epic_id'] ?? null),
            'release_id'   => $this->idOf(Release::class, $project, $data['release_id'] ?? null),
            'story_points' => $data['story_points'] ?? $task->story_points,
        ]);

        return back()->with('success', __('project_management.flash.story_updated'));
    }

    public function rankStory(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'after'  => ['nullable', 'uuid'],
            'before' => ['nullable', 'uuid'],
        ]);

        $find = fn (?string $u) => $u
            ? Task::where('project_id', $project->id)->where('uuid', $u)->first()
            : null;

        $this->agile->rank(
            Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail(),
            $find($data['after'] ?? null),
            $find($data['before'] ?? null),
        );

        return back()->with('success', __('project_management.flash.backlog_ordered'));
    }

    // ── Epics and releases ──────────────────────────────────────────────────

    public function storeEpic(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->agile->createEpic($project, $request->validate([
            'name'        => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'colour'      => ['nullable', 'string', 'max:20'],
            'target_date' => ['nullable', 'date'],
        ]));

        return back()->with('success', __('project_management.flash.epic_created'));
    }

    public function storeRelease(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->agile->createRelease($project, $request->validate([
            'version'     => ['required', 'string', 'max:40'],
            'name'        => ['nullable', 'string', 'max:190'],
            'target_date' => ['nullable', 'date'],
            'environment' => ['nullable', 'string', 'max:40'],
            'notes'       => ['nullable', 'string', 'max:10000'],
        ]));

        return back()->with('success', __('project_management.flash.release_created'));
    }

    public function shipRelease(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->agile->ship(
            Release::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail(),
            [
                ...$request->validate([
                    'revision' => ['nullable', 'string', 'max:120'],
                    'notes'    => ['nullable', 'string', 'max:10000'],
                ]),
                'released_by' => $request->user()->id,
            ],
        );

        return back()->with('success', __('project_management.flash.release_shipped'));
    }

    private function sprint(Project $project, string $uuid): Sprint
    {
        return Sprint::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** A uuid from another project must resolve to nothing, not to somebody else's sprint. */
    private function idOf(string $model, Project $project, ?string $uuid): ?int
    {
        return $uuid ? $model::where('project_id', $project->id)->where('uuid', $uuid)->value('id') : null;
    }
}
