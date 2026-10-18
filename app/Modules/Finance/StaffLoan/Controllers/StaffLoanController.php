<?php

namespace App\Modules\Finance\StaffLoan\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Models\StaffLoanPolicyViolation;
use App\Modules\Finance\StaffLoan\Models\StaffLoanType;
use App\Modules\Finance\StaffLoan\Services\StaffLoanAIService;
use App\Modules\Finance\StaffLoan\Services\StaffLoanPolicyGuard;
use App\Modules\Finance\StaffLoan\Services\StaffLoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffLoanController extends Controller
{
    public function __construct(
        private StaffLoanService     $service,
        private StaffLoanPolicyGuard $policyGuard,
        private StaffLoanAIService   $aiService,
    ) {}

    public function index(Request $request): Response
    {
        $user  = $request->user();
        $query = StaffLoan::with(['employee', 'loanType'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where(function ($s) use ($request) {
                $s->where('reference_number', 'ilike', "%{$request->search}%")
                  ->orWhere('purpose', 'ilike', "%{$request->search}%");
            }))
            ->orderByDesc('created_at');

        if (!$user->hasRole(['super admin', 'administrator', 'finance manager', 'hr manager'])) {
            $query->where('employee_id', $user->id);
        }

        return Inertia::render('Modules/Finance/StaffLoan/Loans/Index', [
            'loans'   => $query->paginate(25)->withQueryString()->through(fn($l) => [
                'id'               => $l->id,
                'uuid'             => $l->uuid,
                'reference_number' => $l->reference_number,
                'employee'         => $l->employee?->name,
                'loan_type'        => $l->loanType?->name,
                'amount_requested' => $l->amount_requested,
                'outstanding_balance'=> $l->outstanding_balance,
                'term_months'      => $l->term_months,
                'monthly_installment'=> $l->monthly_installment,
                'status'           => $l->status,
                'risk_level'       => $l->risk_level,
                'affordability_score'=> $l->affordability_score,
                'next_payment_date'=> $l->next_payment_date?->toDateString(),
                'created_at'       => $l->created_at->toISOString(),
            ]),
            'filters'    => $request->only('status', 'search'),
            'loanTypes'  => StaffLoanType::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Finance/StaffLoan/Loans/Create', [
            'loanTypes'   => StaffLoanType::where('is_active', true)->get(),
            'employees'   => User::with('person:id,user_id,first_name,surname')->get(['id'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'loan_type_id'       => 'nullable|exists:staff_loan_types,id',
            'amount_requested'   => 'required|numeric|min:100|max:500000',
            'term_months'        => 'required|integer|min:1|max:120',
            'purpose'            => 'required|string|max:1000',
            'employment_status'  => 'nullable|string|max:50',
            'is_permanent'       => 'boolean',
            'probation_completed'=> 'boolean',
            'is_suspended'       => 'boolean',
            'monthly_net_pay'    => 'nullable|numeric|min:0',
            'notes'              => 'nullable|string',
        ]);

        $loan = $this->service->createStaffLoanRequest($data, $request->user()->id);

        // Policy guard — throws ValidationException on block
        $policyResult = $this->policyGuard->forLoanSubmitted($loan, $request->user()->id);

        foreach ($policyResult['warnings'] as $warning) {
            StaffLoanPolicyViolation::create([
                'loan_id'               => $loan->id,
                'employee_id'           => $loan->employee_id,
                'violation_type'        => 'policy_warning',
                'violation_description' => $warning,
                'severity'              => 'medium',
                'policy_rule'           => $warning,
                'status'                => 'open',
                'detected_at'           => now(),
            ]);
        }

        if ($policyResult['requires_escalation']) {
            StaffLoanPolicyViolation::create([
                'loan_id'               => $loan->id,
                'employee_id'           => $loan->employee_id,
                'violation_type'        => 'escalation_required',
                'violation_description' => 'Loan flagged for executive review by policy engine.',
                'severity'              => 'high',
                'status'                => 'escalated',
                'detected_at'           => now(),
            ]);
        }

        // AI risk score
        $aiResult = $this->runAIRiskScore($loan);
        $loan->update(['risk_score' => $aiResult['score'], 'risk_level' => $aiResult['level'], 'ai_flags' => $aiResult['flags']]);

        return redirect()->route('staff-loans.loans.show', $loan->uuid)->with('success', 'Loan application submitted.');
    }

    public function show(StaffLoan $loan): Response
    {
        $loan->load(['employee', 'loanType', 'approvedBy', 'rejectedBy', 'repayments', 'approvals.approver', 'policyViolations', 'affordabilityChecks']);

        return Inertia::render('Modules/Finance/StaffLoan/Loans/Show', [
            'loan' => [
                'id'                   => $loan->id,
                'uuid'                 => $loan->uuid,
                'reference_number'     => $loan->reference_number,
                'employee'             => $loan->employee?->name,
                'loan_type'            => $loan->loanType?->name,
                'amount_requested'     => $loan->amount_requested,
                'amount_approved'      => $loan->amount_approved,
                'outstanding_balance'  => $loan->outstanding_balance,
                'monthly_installment'  => $loan->monthly_installment,
                'interest_rate'        => $loan->interest_rate,
                'term_months'          => $loan->term_months,
                'purpose'              => $loan->purpose,
                'employment_status'    => $loan->employment_status,
                'is_permanent'         => $loan->is_permanent,
                'probation_completed'  => $loan->probation_completed,
                'monthly_net_pay'      => $loan->monthly_net_pay,
                'status'               => $loan->status,
                'rejection_reason'     => $loan->rejection_reason,
                'risk_score'           => $loan->risk_score,
                'risk_level'           => $loan->risk_level,
                'ai_flags'             => $loan->ai_flags,
                'affordability_score'  => $loan->affordability_score,
                'repayment_progress'   => $loan->repaymentProgressPct(),
                'overdue_installments' => $loan->overdueInstallments(),
                'next_payment_date'    => $loan->next_payment_date?->toDateString(),
                'approved_by'          => $loan->approvedBy?->name,
                'approved_at'          => $loan->approved_at?->toISOString(),
                'disbursed_at'         => $loan->disbursed_at?->toISOString(),
                'closed_at'            => $loan->closed_at?->toISOString(),
                'notes'                => $loan->notes,
                'created_at'           => $loan->created_at->toISOString(),
            ],
            'repayments'       => $loan->repayments->map(fn($r) => [
                'id'                 => $r->id,
                'installment_number' => $r->installment_number,
                'due_date'           => $r->due_date->toDateString(),
                'amount_due'         => $r->amount_due,
                'amount_paid'        => $r->amount_paid,
                'balance_after'      => $r->balance_after,
                'status'             => $r->status,
                'paid_at'            => $r->paid_at?->toISOString(),
            ]),
            'approvals'        => $loan->approvals->map(fn($a) => [
                'approver'       => $a->approver?->name,
                'approval_level' => $a->approval_level,
                'action'         => $a->action,
                'comments'       => $a->comments,
                'acted_at'       => $a->acted_at->toISOString(),
            ]),
            'policyViolations' => $loan->policyViolations->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'status'      => $v->status,
            ]),
            'affordabilityCheck'=> $loan->affordabilityChecks->last()?->only([
                'monthly_net_pay', 'existing_deductions', 'proposed_deduction',
                'total_deductions_after', 'affordability_ratio', 'disposable_income', 'passed', 'failure_reason',
            ]),
        ]);
    }

    public function approve(Request $request, StaffLoan $loan): RedirectResponse
    {
        $data = $request->validate(['level' => 'required|in:line_manager,hr,finance,executive', 'comments' => 'nullable|string']);
        $this->service->approveLoanRequest($loan, $request->user()->id, $data['level'], $data['comments'] ?? null);

        activity('StaffLoan')
            ->causedBy($request->user())
            ->performedOn($loan)
            ->event('approved')
            ->withProperties(['reference_number' => $loan->reference_number, 'level' => $data['level'], 'amount_requested' => $loan->amount_requested])
            ->log("Staff loan {$loan->reference_number} approved at {$data['level']} level.");

        return back()->with('success', 'Loan approved at ' . ucwords(str_replace('_', ' ', $data['level'])) . ' level.');
    }

    public function reject(Request $request, StaffLoan $loan): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000', 'level' => 'required|in:line_manager,hr,finance,executive']);
        $this->service->rejectLoanRequest($loan, $request->user()->id, $data['reason'], $data['level']);

        activity('StaffLoan')
            ->causedBy($request->user())
            ->performedOn($loan)
            ->event('rejected')
            ->withProperties(['reference_number' => $loan->reference_number, 'reason' => $data['reason'], 'level' => $data['level']])
            ->log("Staff loan {$loan->reference_number} rejected.");

        return back()->with('success', 'Loan application rejected.');
    }

    public function disburse(Request $request, StaffLoan $loan): RedirectResponse
    {
        $data = $request->validate(['amount_approved' => 'required|numeric|min:1']);
        $this->service->disburseLoan($loan, $data['amount_approved'], $request->user()->id);

        activity('StaffLoan')
            ->causedBy($request->user())
            ->performedOn($loan)
            ->event('disbursed')
            ->withProperties(['reference_number' => $loan->reference_number, 'amount_approved' => $data['amount_approved']])
            ->log("Staff loan {$loan->reference_number} disbursed.");

        return back()->with('success', 'Loan disbursed. Repayment schedule generated.');
    }

    public function recordRepayment(Request $request, StaffLoan $loan): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'method' => 'nullable|string']);
        $this->service->recordRepayment($loan, $data['amount'], $request->user()->id, $data['method'] ?? 'payroll');

        activity('StaffLoan')
            ->causedBy($request->user())
            ->performedOn($loan)
            ->event('repayment_recorded')
            ->withProperties(['reference_number' => $loan->reference_number, 'amount' => $data['amount'], 'method' => $data['method'] ?? 'payroll'])
            ->log("Repayment of R{$data['amount']} recorded for loan {$loan->reference_number}.");

        return back()->with('success', 'Repayment recorded.');
    }

    public function close(Request $request, StaffLoan $loan): RedirectResponse
    {
        $this->service->closeLoanAccount($loan, $request->user()->id);

        activity('StaffLoan')
            ->causedBy($request->user())
            ->performedOn($loan)
            ->event('closed')
            ->withProperties(['reference_number' => $loan->reference_number])
            ->log("Staff loan {$loan->reference_number} account closed.");

        return back()->with('success', 'Loan account closed.');
    }

    private function runAIRiskScore(StaffLoan $loan): array
    {
        $monthlyNetPay = (float) ($loan->monthly_net_pay ?? 0);
        $estimate      = $monthlyNetPay > 0
            ? $this->aiService->calculateAffordabilityScore($loan->employee_id, $monthlyNetPay, (float) $loan->monthly_installment ?? 0)
            : [];

        $depFlags   = $this->aiService->detectLoanDependencyRisk($loan->employee_id);
        $deductFlags= $monthlyNetPay > 0 ? $this->aiService->detectHighDeductionBurden($loan->employee_id, $monthlyNetPay) : [];

        $allFlags = array_merge($depFlags, $deductFlags);
        $score    = 0;
        foreach ($allFlags as $f) {
            $score += match ($f['severity'] ?? 'low') { 'critical' => 35, 'high' => 25, 'medium' => 15, default => 8 };
        }

        if ($monthlyNetPay > 0 && isset($estimate['deduction_ratio']) && $estimate['deduction_ratio'] > 25) {
            $score += 20;
        }

        $score = min(100, $score);
        return [
            'score' => $score,
            'level' => match (true) { $score >= 75 => 'critical', $score >= 50 => 'high', $score >= 25 => 'medium', default => 'low' },
            'flags' => $allFlags,
        ];
    }
}
