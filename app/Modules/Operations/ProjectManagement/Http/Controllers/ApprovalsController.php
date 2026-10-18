<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\ApprovalService;
use App\Modules\Operations\ProjectManagement\Services\CostEntryService;
use App\Modules\Operations\ProjectManagement\Services\ProjectContractService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approvals inbox (EPIC-090).
 *
 * One page for "what is waiting on me", across every kind of thing this module routes, plus what
 * the person submitted and is still waiting on. Both halves matter: a queue you cannot see is one
 * nobody works, and a submission you cannot track is one people chase by walking to a desk.
 *
 * Deciding here is two acts, deliberately kept together in one request: the engine records the
 * decision, and then the subject is told. A timesheet whose approval request says "approved" while
 * the timesheet itself still says "submitted" is two systems disagreeing about the same fact, and
 * the cost report would be built from the wrong one.
 */
class ApprovalsController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly TimesheetService $timesheets,
        private readonly CostEntryService $costs,
        private readonly ProjectContractService $contracts,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        return Inertia::render('Modules/ProjectManagement/Approvals/Index', [
            'inbox'  => $this->approvals->inboxFor()->map(fn (ApprovalRequest $r) => $this->present($r, true))->all(),
            'mine'   => $this->approvals->submittedBy()->map(fn (ApprovalRequest $r) => $this->present($r, false))->all(),
            'tab'    => $request->query('tab') === 'mine' ? 'mine' : 'inbox',
            'types'  => \App\Modules\Operations\ProjectManagement\Models\ApprovalFlow::SUBJECT_TYPES,
        ]);
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $approval = ApprovalRequest::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        try {
            $decided = $this->approvals->approve($approval, $data['comment'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        // Only once the ladder has actually been climbed does the subject change. An intermediate
        // signature is not an approval of the thing, only of that rung.
        if ($decided->status === 'approved') {
            $this->applyApproval($decided);
        }

        return back()->with('success', __('project_management.flash.approval_recorded'));
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $approval = ApprovalRequest::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $decided = $this->approvals->reject($approval, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        $this->applyRejection($decided, $data['reason']);

        return back()->with('success', __('project_management.flash.approval_rejected'));
    }

    /** Withdraw your own submission — the polite alternative to asking somebody to reject it. */
    public function cancel(string $uuid): RedirectResponse
    {
        $approval = ApprovalRequest::where('uuid', $uuid)->firstOrFail();

        abort_unless((int) $approval->requested_by === (int) auth()->id(), 403);

        $this->approvals->cancel($approval, __('project_management.approvals.withdrawn_by_submitter'));

        return back()->with('success', __('project_management.flash.approval_withdrawn'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function applyApproval(ApprovalRequest $approval): void
    {
        match ($approval->subject_type) {
            'timesheet' => $this->withSubject(TimeEntry::class, $approval, fn (TimeEntry $e) => $this->timesheets->applyApproval($e)),
            'expense'   => $this->withSubject(CostEntry::class, $approval, fn (CostEntry $e) => $this->costs->approve($e)),
            'contract'  => $this->withSubject(ProjectContract::class, $approval, fn (ProjectContract $c) => $this->contracts->applyApproval($c)),
            default     => null,
        };
    }

    private function applyRejection(ApprovalRequest $approval, string $reason): void
    {
        match ($approval->subject_type) {
            'timesheet' => $this->withSubject(TimeEntry::class, $approval, fn (TimeEntry $e) => $this->timesheets->reject($e, $reason)),
            'expense'   => $this->withSubject(CostEntry::class, $approval, fn (CostEntry $e) => $this->costs->reject($e, $reason)),
            'contract'  => $this->withSubject(ProjectContract::class, $approval, fn (ProjectContract $c) => $this->contracts->applyRejection($c, $reason)),
            default     => null,
        };
    }

    /**
     * @param  class-string  $model
     */
    private function withSubject(string $model, ApprovalRequest $approval, callable $apply): void
    {
        $subject = $model::find($approval->subject_id);

        if (! $subject) {
            // The thing was deleted while it sat in the queue. The decision is still a fact worth
            // keeping, so nothing is unwound — there is simply nothing left to apply it to.
            return;
        }

        try {
            $apply($subject);
        } catch (\InvalidArgumentException) {
            // Already in the target state, usually because two approvers acted at once. The
            // decision stands; there is nothing further to do.
        }
    }

    /** @return array<string,mixed> */
    private function present(ApprovalRequest $r, bool $actionable): array
    {
        $step = $r->currentStep();

        return [
            'id'        => $r->uuid,
            'subject'   => $r->subject_type,
            'label'     => $r->subject_label,
            'project'   => $r->project ? ['id' => $r->project->uuid, 'code' => $r->project->code, 'name' => $r->project->name] : null,
            'amount'    => $r->amount !== null ? (float) $r->amount : null,
            'currency'  => $r->currency,
            'status'    => $r->status,
            'note'      => $r->note,
            'reason'    => $r->decision_reason,
            'step'      => $step?->name,
            'position'  => $r->current_position,
            'steps'     => $r->flow?->steps->count() ?? 1,
            'flow'      => $r->flow?->name,
            'waiting'   => $r->waitingHours(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'can_act'   => $actionable,
            'can_cancel' => $r->status === 'pending' && (int) $r->requested_by === (int) auth()->id(),
        ];
    }
}
