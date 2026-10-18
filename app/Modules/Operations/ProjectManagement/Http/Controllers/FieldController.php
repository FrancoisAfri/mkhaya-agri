<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Work: one board of everything assigned to a person, across every project.
 *
 * ── Why this is served from the server ───────────────────────────────────────
 *
 * It used to render entirely from IndexedDB, filled by /api/v1/pm/sync, so that a phone in a yard
 * with no reception showed the day's work rather than an error. Good goal, one bad consequence: a
 * browser that had never completed a sync showed "No work assigned to you yet" to somebody holding
 * a list of assigned tasks, and the page had no way to tell the two situations apart. An empty
 * cache and an empty workload looked identical, and the reassuring sentence was the wrong one.
 *
 * The board now comes down with the page. The offline cache is still there and still fills from
 * the same sync, but it is the fallback rather than the source — so the failure mode is "this is
 * what we last saw" instead of "you have nothing to do".
 *
 * ── Why a status change here is not a copy of the project board's ────────────
 *
 * Moving a card posts to project-management.tasks.update, the same endpoint the project board
 * posts to. Not a parallel path that happens to write the same column — the same write, with the
 * same authorisation and the same side effects. There is one status per task and both screens are
 * looking at it, which is the only arrangement where "I moved it to done here" cannot disagree
 * with what the project shows.
 */
class FieldController extends Controller
{
    /** The columns, left to right. Cancelled and archived are outcomes, not places work sits. */
    private const COLUMNS = ['not_started', 'in_progress', 'blocked', 'done'];

    public function __construct(private readonly PermissionEvaluator $evaluator)
    {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Task::class);

        $viewer = $request->user();
        $projectIds = $this->visibleProjectIds($viewer);

        $people = $this->peopleVisibleTo($viewer, $projectIds);

        /*
         * Whose board this is. A manager may look at somebody else's; anybody else looking at
         * somebody else's silently gets their own, because a query parameter is not authorisation.
         */
        $requested = (int) $request->query('user', 0);
        $subjectId = collect($people)->contains(fn ($p) => $p['id'] === $requested)
            ? $requested
            : (int) $viewer->id;

        return Inertia::render('Modules/ProjectManagement/Field/Index', [
            /*
             * Keys, not labels. The browser already carries `task_status.*` in twelve languages,
             * and translating here would bake whichever locale the request happened to carry into
             * the payload — the same reasoning TaskStatus::builtIn() gives for sending a null
             * label rather than a translated one.
             */
            'columns' => self::COLUMNS,
            'tasks'   => $this->boardFor($subjectId, $projectIds),
            'people'  => $people,
            'subject' => $subjectId,
            // A person with nobody else to look at should not be shown a picker of one.
            'canPickPerson' => count($people) > 1,
        ]);
    }

    /** @return array<int, int>|null null means every project. */
    private function visibleProjectIds(User $viewer): ?array
    {
        return $this->evaluator->visibleProjectIds($viewer);
    }

    /**
     * The cards, already in columns.
     *
     * Grouped server-side because the grouping is not a display detail: a task whose status is not
     * one of the four — cancelled, archived — belongs on no column and must not silently land in
     * the first one, which is what a client-side `groupBy` does with an unrecognised key.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function boardFor(int $userId, ?array $projectIds): array
    {
        $memberIds = ProjectMember::query()
            ->where('user_id', $userId)
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->pluck('id');

        $board = array_fill_keys(self::COLUMNS, []);

        if ($memberIds->isEmpty()) {
            return $board;
        }

        $taskIds = TaskAssignment::whereIn('member_id', $memberIds)->pluck('task_id');

        if ($taskIds->isEmpty()) {
            return $board;
        }

        $tasks = Task::query()
            ->whereIn('id', $taskIds)
            ->whereIn('status', self::COLUMNS)
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->real()
            ->with('project:id,uuid,code,name')
            ->orderByRaw('planned_end IS NULL, planned_end')
            ->limit(500)
            ->get();

        foreach ($tasks as $task) {
            $board[$task->status][] = [
                'id'       => $task->uuid,
                'code'     => $task->code,
                'name'     => $task->name,
                'status'   => $task->status,
                'priority' => $task->priority,
                'due'      => optional($task->planned_end)->toDateString(),
                // Every write goes back through the project's own route, so the card has to carry
                // which project it belongs to.
                'project'  => [
                    'id'   => $task->project?->uuid,
                    'code' => $task->project?->code,
                    'name' => $task->project?->name,
                ],
            ];
        }

        return $board;
    }

    /**
     * The people this viewer may open a board for.
     *
     * Membership of a project they can see is the test. It reads as the right rule from both ends:
     * a manager gets their crew because their crew is on their jobs, and somebody with one project
     * cannot enumerate the company by changing a number in the address bar.
     *
     * @return list<array{id: int, name: string}>
     */
    private function peopleVisibleTo(User $viewer, ?array $projectIds): array
    {
        $userIds = ProjectMember::query()
            ->whereNotNull('user_id')
            ->when($projectIds !== null, fn ($q) => $q->whereIn('project_id', $projectIds))
            ->distinct()
            ->pluck('user_id');

        // Always themselves, even before anybody has put them on a project — otherwise the board
        // has no subject at all and the page cannot render.
        $userIds = $userIds->push($viewer->id)->unique();

        return User::query()
            ->whereIn('id', $userIds)
            ->with('person:id,user_id,first_name,surname')
            ->get()
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                /*
                 * There is no users.name column on this platform, so a name comes from the
                 * hr_people record — and falls back to the email rather than to nothing.
                 *
                 * Not every project member is staff. A subcontractor's foreman or a client's
                 * representative is a member with work assigned and no employment record, and
                 * dropping the nameless left a manager unable to open the board of exactly the
                 * people whose work they most need to look at. An email is a poor label; an
                 * absent person is a worse one.
                 */
                'name' => trim(($u->person?->first_name ?? '') . ' ' . ($u->person?->surname ?? ''))
                    ?: (string) $u->email,
            ])
            ->filter(fn (array $row) => $row['name'] !== '')
            ->sortBy('name')
            ->values()
            ->all();
    }
}
