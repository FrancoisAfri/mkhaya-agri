<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\CostEntryService;
use App\Modules\Operations\ProjectManagement\Services\CostService;
use App\Modules\Operations\ProjectManagement\Services\TeamService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivery: who is on the job, what hours they booked, and what it has cost (EPIC-030, 031, 040).
 *
 * One page, because they are one question. A resource plan without hours is a wish, hours without
 * cost are a payroll problem rather than a project one, and cost without either is a number nobody
 * can act on. Splitting them across three screens makes the connection somebody's job to remember.
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly TimesheetService $timesheets,
        private readonly CostService $costs,
        private readonly CostEntryService $costEntries,
        private readonly TeamService $team,
    ) {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $from = CarbonImmutable::parse($request->query('from', now()->startOfMonth()->toDateString()));
        $to = CarbonImmutable::parse($request->query('to', now()->endOfMonth()->toDateString()));

        $canSeeCost = $request->user()->can('viewCosts', $project);

        return Inertia::render('Modules/ProjectManagement/Projects/Delivery', [
            'project' => [
                'id' => $project->uuid, 'code' => $project->code, 'name' => $project->name,
                // The booking form labels itself from this: "submit for approval" is a lie on a
                // project where the hours are approved the moment they are booked.
                'timesheet_approval_required' => (bool) $project->timesheet_approval_required,
            ],
            'window'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'team'    => $this->assignments->team($project, $from, $to),
            'members' => ProjectMember::where('project_id', $project->id)->active()
                ->get(['uuid', 'display_name', 'role_key', 'email', 'user_id'])
                ->map(fn (ProjectMember $m) => [
                    'id' => $m->uuid, 'name' => $m->display_name, 'role' => $m->role_key,
                    'email' => $m->email,
                    // Whether a portal invitation is possible, and whether it is still needed.
                    // Only client and contractor members can be invited — an employee signs in
                    // through the front door — and somebody who already has a login needs nothing.
                    'external'  => in_array($m->role_key, ['client', 'contractor'], true),
                    'has_login' => $m->user_id !== null,
                ])
                ->all(),
            'tasks'   => Task::where('project_id', $project->id)->real()
                ->get(['uuid', 'code', 'name'])
                ->map(fn (Task $t) => ['id' => $t->uuid, 'label' => "{$t->code} · {$t->name}"])
                ->all(),
            'entries' => $this->entries($project, $from, $to),
            'hours'   => $this->timesheets->summary($project),
            // Cost is withheld from roles that may see the work but not the money (PM-1022):
            // a subcontractor on a shared project has no business seeing the margin.
            'cost'    => $canSeeCost ? $this->costs->forProject($project) : null,
            // Non-labour cost — materials, plant, subcontractors. Under the same grant as the rest
            // of the money, and absent from the payload rather than hidden in the page.
            'expenses' => $canSeeCost ? $this->expenseRows($project) : [],
            'expenseSummary' => $canSeeCost ? $this->costEntries->summary($project) : null,
            'categories' => CostEntry::CATEGORIES,
            'roles'   => TeamService::ROLES,
            // What the member picker can offer. With HR installed it is a directory; without it,
            // typing a name — which has to keep working, because a subcontractor's foreman is a
            // person on the job whether or not anybody has given him a login.
            'directory' => $this->team->hasDirectory(),
            'can'     => [
                'assign'  => $request->user()->can('update', $project),
                'approve' => $request->user()->can('update', $project),
                'members' => $request->user()->can('manageMembers', $project),
                'costs'   => $canSeeCost,
            ],
        ]);
    }

    // ── People (EPIC-030) ────────────────────────────────────────────────────

    /**
     * Put somebody on the project.
     *
     * This is the step everything downstream was waiting on. Without members there is nobody to
     * assign a task to, nobody to book hours against, and the labour-cost report is a page of
     * zeroes — which is exactly what the Delivery page was showing.
     */
    public function addMember(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:190'],
            'employee_id'  => ['nullable', 'string', 'max:64'],
            'user_id'      => ['nullable', 'integer'],
            'email'        => ['nullable', 'email', 'max:190'],
            // Retired keys are accepted and translated by TeamService::normaliseRole. A stale tab
            // posting `subcontractor` should land the member in the right role, not fail a
            // validation message that names a value the user never chose.
            'role_key'     => ['required', Rule::in(TeamService::acceptedRoleKeys())],
        ]);

        try {
            $this->team->add($project, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['display_name' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.member_added'));
    }

    public function updateMember(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'role_key'     => ['nullable', Rule::in(TeamService::acceptedRoleKeys())],
            'display_name' => ['nullable', 'string', 'max:190'],
            'email'        => ['nullable', 'email', 'max:190'],
        ]);

        $this->team->update($member, $data);

        return back()->with('success', __('project_management.flash.member_updated'));
    }

    public function removeMember(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        // Spelled out rather than built from the outcome: a key assembled by concatenation is one
        // no search finds, and the translation test can only guard keys it can see.
        $message = match ($this->team->remove($member)) {
            'deactivated' => __('project_management.flash.member_deactivated'),
            default       => __('project_management.flash.member_removed'),
        };

        return back()->with('success', $message);
    }

    /** The directory behind the picker, when there is one. */
    public function memberCandidates(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        return response()->json([
            'candidates' => $this->team->candidates((string) $request->query('q', '')),
            'directory'  => $this->team->hasDirectory(),
        ]);
    }

    // ── Non-labour cost (EPIC-040) ───────────────────────────────────────────

    public function storeExpense(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('viewCosts', $project);

        $data = $request->validate([
            'category'       => ['required', Rule::in(CostEntry::CATEGORIES)],
            'description'    => ['required', 'string', 'max:255'],
            'amount'         => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'incurred_on'    => ['required', 'date'],
            'task_id'        => ['nullable', 'uuid'],
            'invoice_number' => ['nullable', 'string', 'max:60'],
            'is_billable'    => ['nullable', 'boolean'],
            // Named for what it does rather than `submit`, which collides with Inertia's own
            // useForm().submit() on the client and gets silently replaced by the method.
            'submit_for_approval' => ['nullable', 'boolean'],
        ]);

        try {
            $entry = $this->costEntries->record($project, $data);

            if ($data['submit_for_approval'] ?? true) {
                $this->costEntries->submit($entry);
            }
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.cost_recorded'));
    }

    public function markExpensePaid(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $entry = CostEntry::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        try {
            $this->costEntries->markPaid($entry);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['expense' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.cost_paid'));
    }

    public function destroyExpense(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $entry = CostEntry::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        // An approved cost has evidence behind it and a figure derived from it. Deleting the row
        // would leave the ledger asserting a cost that no longer exists anywhere.
        abort_unless($entry->isEditable(), 422, __('project_management.delivery.cost_not_editable'));

        $entry->delete();

        return back();
    }

    // ── Assignments ──────────────────────────────────────────────────────────

    public function assign(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $task);

        $data = $request->validate([
            'member_id'      => ['required', 'uuid'],
            'role'           => ['nullable', 'string', 'max:60'],
            'allocation_pct' => ['nullable', 'integer', 'between:1,100'],
            'is_lead'        => ['nullable', 'boolean'],
        ]);

        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $data['member_id'])->firstOrFail();

        try {
            $this->assignments->assign($task, $member, $data);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['member_id' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.assigned'));
    }

    public function unassign(Project $project, string $uuid, string $memberUuid): RedirectResponse
    {
        $task = Task::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $this->authorize('update', $task);

        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $memberUuid)->firstOrFail();

        $this->assignments->unassign($task, $member);

        return back();
    }

    // ── Time ─────────────────────────────────────────────────────────────────

    public function logTime(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $data = $request->validate([
            'member_id'     => ['required', 'uuid'],
            'task_id'       => ['nullable', 'uuid'],
            'worked_on'     => ['required', 'date'],
            'hours'         => ['required', 'numeric', 'min:0.25', 'max:' . TimesheetService::MAX_HOURS_PER_DAY],
            'note'          => ['nullable', 'string', 'max:500'],
            'rate_per_hour' => ['nullable', 'numeric', 'min:0'],
        ]);

        $member = ProjectMember::where('project_id', $project->id)->where('uuid', $data['member_id'])->firstOrFail();

        try {
            $entry = $this->timesheets->log($project, $member, $data);
            $this->timesheets->submit($entry);
        } catch (\InvalidArgumentException $e) {
            // Almost always the day-total rule, which belongs on the hours field.
            return back()->withErrors(['hours' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.time_logged'));
    }

    public function approveTime(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $entry = TimeEntry::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        try {
            $this->timesheets->approve($entry);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.time_approved'));
    }

    public function rejectTime(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $entry = TimeEntry::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        try {
            $this->timesheets->reject($entry, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function expenseRows(Project $project): array
    {
        return CostEntry::where('project_id', $project->id)
            ->with('task:id,uuid,code,name')
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (CostEntry $e) => [
                'id'          => $e->uuid,
                'category'    => $e->category,
                'description' => $e->description,
                'amount'      => (float) $e->amount,
                'currency'    => $e->currency,
                'incurred_on' => $e->incurred_on?->toDateString(),
                'status'      => $e->status,
                'task'        => $e->task ? "{$e->task->code} · {$e->task->name}" : null,
                'invoice'     => $e->invoice_number,
                'reason'      => $e->rejection_reason,
                'editable'    => $e->isEditable(),
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function entries(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return TimeEntry::where('project_id', $project->id)
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->with(['member:id,uuid,display_name', 'task:id,uuid,code,name'])
            ->orderByDesc('worked_on')
            ->limit(500)
            ->get()
            ->map(fn (TimeEntry $e) => [
                'id'        => $e->uuid,
                'member'    => $e->member?->display_name,
                'task'      => $e->task ? "{$e->task->code} · {$e->task->name}" : null,
                'worked_on' => $e->worked_on?->toDateString(),
                'hours'     => (float) $e->hours,
                'status'    => $e->status,
                'note'      => $e->note,
                'reason'    => $e->rejection_reason,
            ])
            ->all();
    }
}
