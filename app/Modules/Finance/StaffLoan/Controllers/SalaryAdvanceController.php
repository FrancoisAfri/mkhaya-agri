<?php

namespace App\Modules\Finance\StaffLoan\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoanPolicyViolation;
use App\Modules\Finance\StaffLoan\Services\StaffLoanAIService;
use App\Modules\Finance\StaffLoan\Services\StaffLoanPolicyGuard;
use App\Modules\Finance\StaffLoan\Services\StaffLoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SalaryAdvanceController extends Controller
{
    public function __construct(
        private StaffLoanService     $service,
        private StaffLoanPolicyGuard $policyGuard,
        private StaffLoanAIService   $aiService,
    ) {}

    public function index(Request $request): Response
    {
        $user  = $request->user();
        $query = SalaryAdvance::with(['employee'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn($q) => $q->where(function ($s) use ($request) {
                $s->where('reference_number', 'ilike', "%{$request->search}%")
                  ->orWhere('reason', 'ilike', "%{$request->search}%");
            }))
            ->orderByDesc('created_at');

        if (!$user->hasRole(['super admin', 'administrator', 'finance manager', 'hr manager'])) {
            $query->where('employee_id', $user->id);
        }

        return Inertia::render('Modules/Finance/StaffLoan/Advances/Index', [
            'advances' => $query->paginate(25)->withQueryString()->through(fn($a) => [
                'id'               => $a->id,
                'uuid'             => $a->uuid,
                'reference_number' => $a->reference_number,
                'employee'         => $a->employee?->name,
                'amount_requested' => $a->amount_requested,
                'amount_approved'  => $a->amount_approved,
                'percentage_of_net'=> $a->percentage_of_net,
                'repayment_month'  => $a->repayment_month->format('M Y'),
                'status'           => $a->status,
                'risk_level'       => $a->risk_level,
                'created_at'       => $a->created_at->toISOString(),
            ]),
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Finance/StaffLoan/Advances/Create', [
            'employees' => User::with('person:id,user_id,first_name,surname')->get(['id'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount_requested'   => 'required|numeric|min:100|max:100000',
            'monthly_net_pay'    => 'nullable|numeric|min:0',
            'repayment_month'    => 'required|date|after_or_equal:today',
            'reason'             => 'required|string|max:1000',
            'employment_status'  => 'nullable|string|max:50',
            'is_permanent'       => 'boolean',
            'probation_completed'=> 'boolean',
            'is_suspended'       => 'boolean',
            'notes'              => 'nullable|string',
        ]);

        $advance = $this->service->createSalaryAdvanceRequest($data, $request->user()->id);

        // Policy guard — throws on block
        $policyResult = $this->policyGuard->forAdvanceSubmitted($advance, $request->user()->id);

        foreach ($policyResult['warnings'] as $warning) {
            StaffLoanPolicyViolation::create([
                'advance_id'            => $advance->id,
                'employee_id'           => $advance->employee_id,
                'violation_type'        => 'policy_warning',
                'violation_description' => $warning,
                'severity'              => 'medium',
                'policy_rule'           => $warning,
                'status'                => 'open',
                'detected_at'           => now(),
            ]);
        }

        // AI risk scoring
        $aiResult = $this->aiService->calculateAdvanceRiskScore($advance);
        $advance->update(['risk_score' => $aiResult['score'], 'risk_level' => $aiResult['level'], 'ai_flags' => $aiResult['flags']]);

        return redirect()->route('staff-loans.advances.show', $advance->uuid)->with('success', 'Salary advance request submitted.');
    }

    public function show(SalaryAdvance $advance): Response
    {
        $advance->load(['employee', 'approvedBy', 'repayments', 'policyViolations', 'affordabilityChecks']);

        return Inertia::render('Modules/Finance/StaffLoan/Advances/Show', [
            'advance' => [
                'id'                => $advance->id,
                'uuid'              => $advance->uuid,
                'reference_number'  => $advance->reference_number,
                'employee'          => $advance->employee?->name,
                'amount_requested'  => $advance->amount_requested,
                'amount_approved'   => $advance->amount_approved,
                'monthly_net_pay'   => $advance->monthly_net_pay,
                'percentage_of_net' => $advance->percentage_of_net,
                'repayment_month'   => $advance->repayment_month->format('F Y'),
                'reason'            => $advance->reason,
                'employment_status' => $advance->employment_status,
                'is_permanent'      => $advance->is_permanent,
                'probation_completed'=> $advance->probation_completed,
                'status'            => $advance->status,
                'rejection_reason'  => $advance->rejection_reason,
                'risk_score'        => $advance->risk_score,
                'risk_level'        => $advance->risk_level,
                'ai_flags'          => $advance->ai_flags,
                'approved_by'       => $advance->approvedBy?->name,
                'approved_at'       => $advance->approved_at?->toISOString(),
                'disbursed_at'      => $advance->disbursed_at?->toISOString(),
                'settled_at'        => $advance->settled_at?->toISOString(),
                'notes'             => $advance->notes,
                'created_at'        => $advance->created_at->toISOString(),
            ],
            'repayments' => $advance->repayments->map(fn($r) => [
                'id'         => $r->id,
                'due_date'   => $r->due_date->toDateString(),
                'amount_due' => $r->amount_due,
                'amount_paid'=> $r->amount_paid,
                'status'     => $r->status,
                'paid_at'    => $r->paid_at?->toISOString(),
            ]),
            'policyViolations' => $advance->policyViolations->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'status'      => $v->status,
            ]),
            'affordabilityCheck'=> $advance->affordabilityChecks->last()?->only([
                'monthly_net_pay', 'proposed_deduction', 'affordability_ratio', 'passed', 'failure_reason',
            ]),
        ]);
    }

    public function approve(Request $request, SalaryAdvance $advance): RedirectResponse
    {
        $data = $request->validate(['comments' => 'nullable|string']);
        $this->service->approveAdvance($advance, $request->user()->id, $data['comments'] ?? null);

        activity('SalaryAdvance')
            ->causedBy(Auth::user())
            ->performedOn($advance)
            ->event('approved')
            ->withProperties(['reference_number' => $advance->reference_number, 'amount_approved' => $advance->amount_approved])
            ->log("Salary advance {$advance->reference_number} approved.");

        return back()->with('success', 'Advance approved.');
    }

    public function reject(Request $request, SalaryAdvance $advance): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $this->service->rejectAdvance($advance, $request->user()->id, $data['reason']);

        activity('SalaryAdvance')
            ->causedBy(Auth::user())
            ->performedOn($advance)
            ->event('rejected')
            ->withProperties(['reference_number' => $advance->reference_number, 'reason' => $data['reason']])
            ->log("Salary advance {$advance->reference_number} rejected.");

        return back()->with('success', 'Advance rejected.');
    }

    public function disburse(Request $request, SalaryAdvance $advance): RedirectResponse
    {
        $this->service->disburseAdvance($advance, $request->user()->id);

        activity('SalaryAdvance')
            ->causedBy(Auth::user())
            ->performedOn($advance)
            ->event('disbursed')
            ->withProperties(['reference_number' => $advance->reference_number, 'amount_approved' => $advance->amount_approved])
            ->log("Salary advance {$advance->reference_number} disbursed.");

        return back()->with('success', 'Advance disbursed.');
    }

    public function settle(Request $request, SalaryAdvance $advance): RedirectResponse
    {
        $this->service->settleAdvance($advance, $request->user()->id);

        activity('SalaryAdvance')
            ->causedBy(Auth::user())
            ->performedOn($advance)
            ->event('settled')
            ->withProperties(['reference_number' => $advance->reference_number])
            ->log("Salary advance {$advance->reference_number} settled.");

        return back()->with('success', 'Advance settled.');
    }
}
