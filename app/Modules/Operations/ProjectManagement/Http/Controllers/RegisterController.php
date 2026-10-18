<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\Issue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\BaselineService;
use App\Modules\Operations\ProjectManagement\Services\RegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The risk, issue and change registers on one page (§11, §12, §13).
 *
 * One page with three tabs rather than three pages, because they are read together: a steering
 * meeting works down the risks, then the issues those risks became, then the changes both are
 * driving. Splitting them across the sidebar makes that a navigation exercise.
 */
class RegisterController extends Controller
{
    public function __construct(private readonly RegisterService $registers)
    {
    }

    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        // Approvals decide; the change request reads the decision. Reconciling here keeps the
        // approval engine free of a growing list of subject types it has to know about.
        $this->registers->reconcileChanges($project);

        $canCost = $request->user()->can('viewCosts', $project);

        return Inertia::render('Modules/ProjectManagement/Projects/Registers', [
            'project' => ['id' => $project->uuid, 'code' => $project->code, 'name' => $project->name, 'currency' => $project->currency],

            'risks' => Risk::where('project_id', $project->id)
                ->orderByDesc('score')->orderBy('code')->get()
                ->map(fn (Risk $r) => [
                    'id'          => $r->uuid,
                    'code'        => $r->code,
                    'title'       => $r->title,
                    'description' => $r->description,
                    'category'    => $r->category,
                    'probability' => $r->probability,
                    'impact'      => $r->impact,
                    'score'       => $r->score,
                    'band'        => $r->band(),
                    'strategy'    => $r->response_strategy,
                    'mitigation'  => $r->mitigation,
                    'contingency' => $r->contingency,
                    'status'      => $r->status,
                    'owner_id'    => $r->owner_user_id,
                    'review_due'  => optional($r->review_due)->toDateString(),
                    'overdue_review' => $r->isOverdueForReview(),
                    'closure_note'   => $r->closure_note,
                    // Money is redacted for a client or contractor exactly as it is everywhere else
                    // in this module; a risk register is not a way around that.
                    'cost_impact'    => $canCost && $r->cost_impact !== null ? (float) $r->cost_impact : null,
                    'schedule_days'  => $r->schedule_impact_days,
                ])->all(),

            'issues' => Issue::where('project_id', $project->id)
                ->orderByRaw("case status when 'open' then 0 when 'in_progress' then 1 when 'resolved' then 2 else 3 end")
                ->orderBy('code')->get()
                ->map(fn (Issue $i) => [
                    'id'          => $i->uuid,
                    'code'        => $i->code,
                    'title'       => $i->title,
                    'description' => $i->description,
                    'kind'        => $i->kind,
                    'severity'    => $i->severity,
                    'priority'    => $i->priority,
                    'status'      => $i->status,
                    'assigned_to' => $i->assigned_to,
                    'due_date'    => optional($i->due_date)->toDateString(),
                    'overdue'     => $i->isOverdue(),
                    'resolution'  => $i->resolution,
                    'root_cause'  => $i->root_cause,
                    'corrective_action'   => $i->corrective_action,
                    'preventative_action' => $i->preventative_action,
                    'needs_root_cause'    => $i->needsRootCause(),
                    'from_risk'   => $i->risk?->code,
                    'cost_impact' => $canCost && $i->cost_impact !== null ? (float) $i->cost_impact : null,
                ])->all(),

            'changes' => ChangeRequest::where('project_id', $project->id)
                ->orderByDesc('id')->get()
                ->map(fn (ChangeRequest $c) => [
                    'id'          => $c->uuid,
                    'code'        => $c->code,
                    'title'       => $c->title,
                    'description' => $c->description,
                    'kind'        => $c->kind,
                    'reason'      => $c->reason,
                    'impact_scope'   => $c->impact_scope,
                    'impact_cost'    => $canCost && $c->impact_cost !== null ? (float) $c->impact_cost : null,
                    'impact_days'    => $c->impact_days,
                    'impact_quality' => $c->impact_quality,
                    'impact_risk'    => $c->impact_risk,
                    'status'      => $c->status,
                    'editable'    => $c->isEditable(),
                    'awaiting_implementation' => $c->isAwaitingImplementation(),
                    'decided_at'    => optional($c->decided_at)->toIso8601String(),
                    'decision_note' => $c->decision_note,
                ])->all(),

            // The pickers. Members rather than every user, for the same reason as everywhere else.
            'members' => ProjectMember::where('project_id', $project->id)->active()
                ->whereNotNull('user_id')->orderBy('display_name')
                ->get(['user_id', 'display_name'])
                ->map(fn (ProjectMember $m) => ['id' => $m->user_id, 'name' => $m->display_name])->all(),
            'tasks'   => Task::where('project_id', $project->id)->orderBy('name')
                ->limit(500)->get(['uuid', 'name'])
                ->map(fn (Task $t) => ['id' => $t->uuid, 'name' => $t->name])->all(),

            'options' => [
                'risk_categories'  => Risk::CATEGORIES,
                'risk_strategies'  => Risk::STRATEGIES,
                'risk_statuses'    => Risk::STATUSES,
                'issue_kinds'      => Issue::KINDS,
                'issue_severities' => Issue::SEVERITIES,
                'issue_statuses'   => Issue::STATUSES,
                'change_kinds'     => ChangeRequest::KINDS,
            ],
            'can' => [
                'manage' => $request->user()->can('update', $project),
                'costs'  => $canCost,
            ],
        ]);
    }

    // ── Risks ───────────────────────────────────────────────────────────────

    public function storeRisk(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->registers->createRisk($project, $this->riskRules($request));

        return back()->with('success', __('project_management.flash.risk_added'));
    }

    public function updateRisk(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->registers->updateRisk($this->risk($project, $uuid), $this->riskRules($request));

        return back()->with('success', __('project_management.flash.risk_updated'));
    }

    public function closeRisk(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate(['closure_note' => ['required', 'string', 'max:2000']]);

        try {
            $this->registers->closeRisk($this->risk($project, $uuid), $data['closure_note']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['closure_note' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.risk_closed'));
    }

    /** The risk came true. Creates the issue and leaves the risk on the record as realised. */
    public function promoteRisk(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'kind'     => ['nullable', Rule::in(Issue::KINDS)],
            'severity' => ['nullable', Rule::in(Issue::SEVERITIES)],
        ]);

        $issue = $this->registers->promoteRisk($this->risk($project, $uuid), $data);

        return back()->with('success', __('project_management.flash.risk_realised', ['code' => $issue->code]));
    }

    public function destroyRisk(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->risk($project, $uuid)->delete();

        return back()->with('success', __('project_management.flash.risk_removed'));
    }

    // ── Issues ──────────────────────────────────────────────────────────────

    public function storeIssue(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->registers->createIssue($project, $this->issueRules($request, $project));

        return back()->with('success', __('project_management.flash.issue_added'));
    }

    public function updateIssue(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->registers->updateIssue($this->issue($project, $uuid), $this->issueRules($request, $project));

        return back()->with('success', __('project_management.flash.issue_updated'));
    }

    public function resolveIssue(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $data = $request->validate([
            'resolution'          => ['required', 'string', 'max:2000'],
            'root_cause'          => ['nullable', 'string', 'max:2000'],
            'corrective_action'   => ['nullable', 'string', 'max:2000'],
            'preventative_action' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->registers->resolveIssue($this->issue($project, $uuid), $data);
        } catch (RuntimeException $e) {
            return back()->withErrors(['root_cause' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.issue_resolved'));
    }

    public function destroyIssue(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->issue($project, $uuid)->delete();

        return back()->with('success', __('project_management.flash.issue_removed'));
    }

    // ── Change requests ─────────────────────────────────────────────────────

    public function storeChange(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->registers->createChange($project, $this->changeRules($request));

        return back()->with('success', __('project_management.flash.change_added'));
    }

    public function updateChange(Request $request, Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $change = $this->change($project, $uuid);

        // A submitted change is in front of an approver; editing it underneath them would mean the
        // thing approved is not the thing that was read.
        abort_unless($change->isEditable(), 422);

        $change->update($this->changeRules($request));

        return back()->with('success', __('project_management.flash.change_updated'));
    }

    public function submitChange(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        try {
            $this->registers->submitChange($this->change($project, $uuid));
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.change_submitted'));
    }

    /** Apply an approved change, taking a baseline so its effect on the plan stays measurable. */
    public function implementChange(Project $project, string $uuid, BaselineService $baselines): RedirectResponse
    {
        $this->authorize('update', $project);

        try {
            $this->registers->implementChange($this->change($project, $uuid), $baselines);
        } catch (RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', __('project_management.flash.change_implemented'));
    }

    public function destroyChange(Project $project, string $uuid): RedirectResponse
    {
        $this->authorize('update', $project);

        $this->change($project, $uuid)->delete();

        return back()->with('success', __('project_management.flash.change_removed'));
    }

    // ────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function riskRules(Request $request): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category'    => ['required', Rule::in(Risk::CATEGORIES)],
            'probability' => ['required', 'integer', 'between:1,5'],
            'impact'      => ['required', 'integer', 'between:1,5'],
            'response_strategy' => ['required', Rule::in(Risk::STRATEGIES)],
            'mitigation'  => ['nullable', 'string', 'max:5000'],
            'contingency' => ['nullable', 'string', 'max:5000'],
            'status'      => ['nullable', Rule::in(Risk::STATUSES)],
            'owner_user_id'        => ['nullable', 'integer'],
            'review_due'           => ['nullable', 'date'],
            'cost_impact'          => ['nullable', 'numeric', 'min:0'],
            'schedule_impact_days' => ['nullable', 'integer', 'between:0,3650'],
        ]);
    }

    /** @return array<string,mixed> */
    private function issueRules(Request $request, Project $project): array
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind'        => ['required', Rule::in(Issue::KINDS)],
            'severity'    => ['required', Rule::in(Issue::SEVERITIES)],
            'priority'    => ['nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status'      => ['nullable', Rule::in(Issue::STATUSES)],
            'assigned_to' => ['nullable', 'integer'],
            'task_id'     => ['nullable', 'uuid'],
            'due_date'    => ['nullable', 'date'],
            'cost_impact' => ['nullable', 'numeric', 'min:0'],
        ]);

        // The form sends a task uuid; the column is the internal key, and a uuid from another
        // project must not resolve here at all.
        if (! empty($data['task_id'])) {
            $data['task_id'] = Task::where('project_id', $project->id)
                ->where('uuid', $data['task_id'])->value('id');
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function changeRules(Request $request): array
    {
        return $request->validate([
            'title'       => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'kind'        => ['required', Rule::in(ChangeRequest::KINDS)],
            'reason'      => ['nullable', 'string', 'max:5000'],
            'impact_scope'   => ['nullable', 'string', 'max:5000'],
            'impact_cost'    => ['nullable', 'numeric'],
            // Signed: a change can pull a date in as well as push it out.
            'impact_days'    => ['nullable', 'integer', 'between:-3650,3650'],
            'impact_quality' => ['nullable', 'string', 'max:2000'],
            'impact_risk'    => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function risk(Project $project, string $uuid): Risk
    {
        return Risk::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function issue(Project $project, string $uuid): Issue
    {
        return Issue::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function change(Project $project, string $uuid): ChangeRequest
    {
        return ChangeRequest::where('project_id', $project->id)->where('uuid', $uuid)->firstOrFail();
    }
}
