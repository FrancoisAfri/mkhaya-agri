<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollApproval;
use App\Modules\HR\Payroll\Models\PayrollApprovalComment;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollApprovalController extends Controller
{
    public function __construct(private PayrollApprovalService $approvalService) {}

    public function index(): Response
    {
        $filters    = request()->only(['status', 'level']);
        $status     = $filters['status'] ?? '';
        $level      = $filters['level']  ?? '';

        $query = PayrollApproval::with([
            'payrollRun:id,uuid,ref_number,name,period_start,period_end,total_net,employee_count,ai_risk_level',
            'submittedBy:id,email',
            'steps.approver:id,email',
            'comments.user:id,email',
        ])->latest();

        if ($status) $query->where('status', $status);
        if ($level)  $query->where('current_level', $level);

        $approvals = $query->paginate(20)->withQueryString();

        // Enrich submittedBy and step approvers with hr_people names
        $userIds = collect();
        foreach ($approvals as $a) {
            if ($a->submitted_by) $userIds->push($a->submitted_by);
            foreach ($a->steps as $s) { if ($s->approver_id) $userIds->push($s->approver_id); }
            foreach ($a->comments as $c) { if ($c->user_id) $userIds->push($c->user_id); }
        }

        $names = DB::table('hr_people')
            ->whereIn('user_id', $userIds->unique()->values())
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $approvals->getCollection()->transform(function ($a) use ($names) {
            $a->submitted_by_name = $names[$a->submitted_by]->full_name ?? ('User #' . $a->submitted_by);
            $a->steps->transform(function ($s) use ($names) {
                $s->approver_name = $names[$s->approver_id]->full_name ?? ('User #' . $s->approver_id);
                return $s;
            });
            $a->comments->transform(function ($c) use ($names) {
                $c->user_name = $names[$c->user_id]->full_name ?? ('User #' . $c->user_id);
                return $c;
            });
            return $a;
        });

        $stats = [
            'pending'   => PayrollApproval::where('status', 'pending')->count(),
            'escalated' => PayrollApproval::where('status', 'escalated')->count(),
            'approved'  => PayrollApproval::where('status', 'approved')->count(),
            'rejected'  => PayrollApproval::where('status', 'rejected')->count(),
        ];

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name', 'status')
            ->whereNotIn('status', ['released', 'cancelled'])
            ->latest('period_start')->limit(30)->get();

        return Inertia::render('Modules/HR/Payroll/Approvals/Index', [
            'approvals' => $approvals,
            'filters'   => $filters,
            'stats'     => $stats,
            'runs'      => $runs,
            'levels'    => $this->approvalService->getLevels(),
        ]);
    }

    // ── Submit a run for review (creates approval record) ─────────────────────

    public function submit(PayrollRun $run): RedirectResponse
    {
        $block = $this->approvalService->blockPayrollRelease($run);

        if ($block['blocked'] && request()->boolean('force') === false) {
            return back()->with('error', 'Cannot submit: ' . implode(' ', $block['reasons']));
        }

        $approval = $this->approvalService->submitPayrollForReview($run);

        $msg = $approval->auto_escalated
            ? "Run {$run->ref_number} submitted and auto-escalated to executive level due to critical anomalies."
            : "Run {$run->ref_number} submitted for payroll officer review.";

        return redirect()->route('payroll.approvals.index')->with('success', $msg);
    }

    // ── Approval actions ──────────────────────────────────────────────────────

    public function approve(Request $request, PayrollApproval $approval): RedirectResponse
    {
        $request->validate(['comments' => 'nullable|string|max:2000']);

        $ok = $this->approvalService->approvePayrollRun($approval, $request->comments);

        if (!$ok) {
            return back()->with('error', 'This approval cannot be actioned in its current state.');
        }

        $approval->refresh();
        $msg = $approval->status === 'approved'
            ? "Final approval granted for {$approval->payrollRun?->ref_number}. Run is ready for release."
            : "Approved at {$approval->steps->last()?->level} level. Forwarded to next approver.";

        return back()->with('success', $msg);
    }

    public function reject(Request $request, PayrollApproval $approval): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:10|max:2000']);

        $ok = $this->approvalService->rejectPayrollRun($approval, $request->reason);

        return back()->with($ok ? 'success' : 'error', $ok
            ? "Payroll run rejected and returned to draft."
            : "This approval cannot be rejected in its current state.");
    }

    public function requestCorrection(Request $request, PayrollApproval $approval): RedirectResponse
    {
        $request->validate(['notes' => 'required|string|min:10|max:2000']);

        $ok = $this->approvalService->requestPayrollCorrection($approval, $request->notes);

        return back()->with($ok ? 'success' : 'error', $ok
            ? "Correction requested. Run returned to draft for payroll officer to fix."
            : "Cannot request correction in the current state.");
    }

    public function escalate(Request $request, PayrollApproval $approval): RedirectResponse
    {
        $request->validate(['reason' => 'nullable|string|max:2000']);

        $ok = $this->approvalService->escalateHighRiskPayroll(
            $approval,
            $request->input('reason', 'Manually escalated to executive level.')
        );

        return back()->with($ok ? 'success' : 'error', $ok
            ? "Approval escalated to executive level."
            : "Cannot escalate in the current state.");
    }

    public function comment(Request $request, PayrollApproval $approval): RedirectResponse
    {
        $request->validate([
            'comment'     => 'required|string|max:2000',
            'is_internal' => 'boolean',
        ]);

        $this->approvalService->addComment($approval, $request->comment, $request->boolean('is_internal'));

        return back()->with('success', 'Comment added.');
    }
}
