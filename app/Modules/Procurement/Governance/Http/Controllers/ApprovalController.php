<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\ApprovalRouter;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\CriteriaService;
use App\Modules\Procurement\Governance\Services\DeviationService;
use App\Modules\Procurement\Governance\Services\PlanningService;
use App\Modules\Procurement\Governance\Services\RecommendationService;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The approvals inbox — one queue across every capability in the module.
 *
 * A procurement manager does not think in terms of "the deviation queue" and "the recommendation
 * queue"; they think about what is waiting for them. So the inbox is subject-agnostic, and the
 * decision handler dispatches back to whichever service owns the subject once the chain completes.
 *
 * Every item shown is filtered through `ApprovalRouter::mayDecide()` rather than by an approver-id
 * column, because a step can be assigned to a role, a department head or a budget owner, and only
 * the router can resolve those. That same call also enforces separation of duties, so an item the
 * viewer raised never appears in their own queue.
 */
class ApprovalController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly ApprovalRouter $approvals,
        private readonly DeviationService $deviations,
        private readonly PlanningService $planning,
        private readonly RecommendationService $recommendations,
        private readonly CriteriaService $criteria,
        private readonly Capabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $userId = (int) $request->user()->id;

        $pending = $companyId === null ? collect() : DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->leftJoin('procgov_records as rec', 'rec.id', '=', 'r.record_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'ACTIVE')
            ->orderBy('s.due_at')
            ->limit(300)
            ->get([
                's.id as step_id', 's.step_no', 's.name', 's.due_at', 's.activated_at',
                's.approver_label', 's.quorum', 's.approvals_received',
                'r.id as request_id', 'r.uuid', 'r.subject_type', 'r.subject_id',
                'r.subject_reference', 'r.subject_label', 'r.amount', 'r.requested_at',
                'r.total_steps', 'r.chain_code',
                'rec.uuid as record_uuid', 'rec.reference as record_reference',
            ])
            ->filter(fn ($row) => $this->approvals->mayDecide((int) $row->request_id, $userId))
            ->map(function ($row) {
                $row->overdue = $row->due_at && Carbon::parse($row->due_at)->isPast();
                $row->waiting_days = $row->activated_at
                    ? Carbon::parse($row->activated_at)->diffInDays(Carbon::now())
                    : null;

                return $row;
            })
            ->values();

        // What the viewer has raised and is waiting on — the other half of "where is my request".
        $mine = $companyId === null ? collect() : DB::table('procgov_approval_requests as r')
            ->where('r.company_id', $companyId)
            ->where('r.requested_by', $userId)
            ->orderByDesc('r.requested_at')
            ->limit(100)
            ->get([
                'r.uuid', 'r.subject_type', 'r.subject_reference', 'r.subject_label', 'r.amount',
                'r.status', 'r.current_step', 'r.total_steps', 'r.requested_at', 'r.completed_at',
                'r.chain_code',
            ]);

        return Inertia::render('Modules/ProcurementGovernance/Approvals/Index', [
            'pending' => $pending,
            'mine' => $mine,
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);

        $approval = DB::table('procgov_approval_requests')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $approval, 404);

        return Inertia::render('Modules/ProcurementGovernance/Approvals/Show', [
            'approval' => $approval,
            'steps' => DB::table('procgov_approval_steps as s')
                ->leftJoin('users as u', 'u.id', '=', 's.decided_by')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->where('s.request_id', $approval->id)
                ->orderBy('s.step_no')
                ->get([
                    's.id', 's.step_no', 's.name', 's.approver_label', 's.status', 's.decision',
                    's.decided_at', 's.comment', 's.due_at', 's.escalated_at', 's.quorum',
                    's.approvals_received',
                    DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as decided_by"),
                ]),
            'subject' => $this->subjectSummary($approval),
            'mayDecide' => $this->approvals->mayDecide((int) $approval->id, (int) $request->user()->id),
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $approval = DB::table('procgov_approval_requests')
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $approval, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // A rejection without a reason is unappealable and unauditable.
        if ($data['decision'] === 'REJECTED' && trim((string) ($data['comment'] ?? '')) === '') {
            return back()->with('error', __('procurement_governance.flash.approvals.reason_required'));
        }

        try {
            $updated = $this->approvals->decide(
                (int) $approval->id,
                (int) $request->user()->id,
                $data['decision'],
                $data['comment'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // The chain has finished — tell the subject's owner so its own status follows.
        if (in_array($updated->status, ['APPROVED', 'REJECTED'], true)) {
            $this->applyOutcome($companyId, $updated, $data['comment'] ?? null);
        }

        return back()->with('success', $updated->status === 'PENDING'
            ? __('procurement_governance.flash.approvals.recorded_next_level')
            : __('procurement_governance.flash.approvals.decided', ['status' => $updated->status]));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Propagate a completed chain back to whatever it was approving. Each branch is wrapped
     * individually: a subject whose own status transition fails must not leave the approval looking
     * undecided, because the decision itself is already recorded and audited.
     */
    private function applyOutcome(int $companyId, object $approval, ?string $comment): void
    {
        try {
            match ($approval->subject_type) {
                'procgov_deviations' => $this->deviations->decide(
                    $companyId,
                    (int) $approval->subject_id,
                    $approval->status,
                    $comment,
                ),

                'procgov_recommendations' => $this->recommendations->decide(
                    $companyId,
                    (int) $approval->subject_id,
                    $approval->status,
                    $comment,
                ),

                'procgov_plans' => $approval->status === 'APPROVED'
                    ? $this->planning->transition($companyId, (int) $approval->subject_id, 'APPROVED', $comment)
                    : $this->planning->transition($companyId, (int) $approval->subject_id, 'PROCUREMENT_REVIEW', $comment),

                'procgov_criteria_changes' => $this->applyCriteriaChange($companyId, $approval),

                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * An approved criteria change has to change the criteria.
     *
     * This branch used to set the row's status and stop there. The committee asked for a change, the
     * chain approved it, the screen said APPROVED — and the evaluation carried on being scored
     * against the criteria the change was meant to replace. `CriteriaService::applyChange()` is the
     * step that inserts, amends or removes the criterion and archives the scores the change
     * invalidates, and nothing was calling it.
     *
     * The two happen together or not at all. `applyChange()` refuses anything not already APPROVED,
     * so the status has to be written first — which means a failure afterwards would otherwise leave
     * a change marked approved and not applied, the exact state this is here to prevent. The outer
     * handler reports and carries on by design, because the decision itself is recorded and audited
     * separately; the transaction makes sure what it carries on from is consistent.
     */
    private function applyCriteriaChange(int $companyId, object $approval): void
    {
        DB::transaction(function () use ($companyId, $approval) {
            DB::table('procgov_criteria_changes')
                ->where('id', $approval->subject_id)
                ->update([
                    'status' => $approval->status,
                    'decided_at' => Carbon::now(),
                    'decided_by' => auth()->id(),
                    'updated_at' => Carbon::now(),
                ]);

            if ($approval->status === 'APPROVED') {
                $this->criteria->applyChange($companyId, (int) $approval->subject_id);
            }
        });
    }

    /** A short description of what is being approved, for the decision screen. */
    private function subjectSummary(object $approval): ?array
    {
        $table = $approval->subject_type;

        if (! in_array($table, [
            'procgov_deviations', 'procgov_recommendations', 'procgov_plans',
            'procgov_criteria_changes', 'procgov_records',
        ], true)) {
            return null;
        }

        $row = DB::table($table)->find($approval->subject_id);

        if (! $row) {
            return null;
        }

        return match ($table) {
            'procgov_deviations' => [
                'type' => 'Deviation',
                'reference' => $row->reference,
                'title' => $row->title,
                'value' => $row->procurement_value,
                'risk_level' => $row->risk_level,
                'compliance' => $row->compliance_result,
                'justification' => $row->business_justification,
                'reason' => $row->reason,
            ],
            'procgov_recommendations' => [
                'type' => 'Award recommendation',
                'reference' => $row->reference,
                'title' => "Award to {$row->recommended_supplier_name}",
                'value' => $row->recommended_amount,
                'narrative' => $row->recommendation,
                'conditions' => $row->award_conditions,
            ],
            'procgov_plans' => [
                'type' => 'Procurement plan',
                'reference' => $row->reference,
                'title' => $row->title,
                'value' => $row->total_estimated_value,
            ],
            'procgov_criteria_changes' => [
                'type' => 'Evaluation criteria change',
                'reference' => null,
                'title' => "{$row->change_type} criterion",
                'reason' => $row->reason,
            ],
            default => [
                'type' => 'Procurement',
                'reference' => $row->reference ?? null,
                'title' => $row->title ?? null,
            ],
        };
    }
}
