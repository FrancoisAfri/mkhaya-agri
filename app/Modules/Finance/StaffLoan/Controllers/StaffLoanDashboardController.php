<?php

namespace App\Modules\Finance\StaffLoan\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Models\StaffLoanPolicyViolation;
use App\Modules\Finance\StaffLoan\Models\StaffLoanRepayment;
use App\Modules\Finance\StaffLoan\Services\StaffLoanAIService;
use Inertia\Inertia;
use Inertia\Response;

class StaffLoanDashboardController extends Controller
{
    public function __construct(private StaffLoanAIService $aiService) {}

    public function index(): Response
    {
        $activeLoans      = StaffLoan::where('status', 'active')->count();
        $activeAdvances   = SalaryAdvance::whereIn('status', ['approved', 'disbursed'])->count();
        $pendingApps      = StaffLoan::where('status', 'pending')->count()
                         + SalaryAdvance::where('status', 'pending')->count();
        $approvedApps     = StaffLoan::where('status', 'approved')->count()
                         + SalaryAdvance::where('status', 'approved')->count();
        $rejectedApps     = StaffLoan::where('status', 'rejected')->count()
                         + SalaryAdvance::where('status', 'rejected')->count();

        $totalOutstanding  = StaffLoan::where('status', 'active')->sum('outstanding_balance');
        $monthlyRepayments = StaffLoan::where('status', 'active')->sum('monthly_installment');

        $defaultRisk = StaffLoan::where('status', 'active')
            ->whereHas('repayments', fn($q) => $q->where('status', 'overdue'))
            ->count();

        $highDeductionCount = StaffLoan::where('status', 'active')
            ->selectRaw('employee_id, SUM(monthly_installment) as total_deduct')
            ->groupBy('employee_id')
            ->havingRaw('SUM(monthly_installment) > 0')
            ->get()
            ->filter(fn($row) => $row->total_deduct > 5000)
            ->count();

        $policyViolations = StaffLoanPolicyViolation::where('status', 'open')->count();

        $overdueRepayments = StaffLoanRepayment::where('status', 'overdue')
            ->whereHas('loan', fn($q) => $q->where('status', 'active'))
            ->count();

        $stats = [
            ['label' => 'Active Loans',           'value' => $activeLoans,       'icon' => 'mdi-bank',             'color' => 'primary'],
            ['label' => 'Active Advances',         'value' => $activeAdvances,    'icon' => 'mdi-cash-fast',        'color' => 'info'],
            ['label' => 'Pending Applications',    'value' => $pendingApps,       'icon' => 'mdi-clock-outline',    'color' => 'warning'],
            ['label' => 'Approved Applications',   'value' => $approvedApps,      'icon' => 'mdi-check-circle',     'color' => 'success'],
            ['label' => 'Rejected Applications',   'value' => $rejectedApps,      'icon' => 'mdi-close-circle',     'color' => 'error'],
            ['label' => 'Monthly Repayments',      'value' => 'R'.number_format($monthlyRepayments, 0), 'icon' => 'mdi-calendar-month', 'color' => 'teal'],
            ['label' => 'Default Risk',            'value' => $defaultRisk,       'icon' => 'mdi-alert-circle',     'color' => 'error'],
            ['label' => 'High Deduction Employees','value' => $highDeductionCount,'icon' => 'mdi-account-alert',    'color' => 'deep-orange'],
            ['label' => 'Policy Violations',       'value' => $policyViolations,  'icon' => 'mdi-shield-alert',     'color' => $policyViolations > 0 ? 'error' : 'success'],
            ['label' => 'Overdue Repayments',      'value' => $overdueRepayments, 'icon' => 'mdi-calendar-remove',  'color' => $overdueRepayments > 0 ? 'error' : 'success'],
        ];

        $recentLoans = StaffLoan::with(['employee', 'loanType'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn($l) => [
                'id'               => $l->id,
                'uuid'             => $l->uuid,
                'reference_number' => $l->reference_number,
                'employee'         => $l->employee?->name,
                'amount_requested' => $l->amount_requested,
                'term_months'      => $l->term_months,
                'status'           => $l->status,
                'risk_level'       => $l->risk_level,
                'created_at'       => $l->created_at->toISOString(),
            ]);

        $recentAdvances = SalaryAdvance::with(['employee'])
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(fn($a) => [
                'id'               => $a->id,
                'uuid'             => $a->uuid,
                'reference_number' => $a->reference_number,
                'employee'         => $a->employee?->name,
                'amount_requested' => $a->amount_requested,
                'percentage_of_net'=> $a->percentage_of_net,
                'status'           => $a->status,
                'created_at'       => $a->created_at->toISOString(),
            ]);

        $violations = StaffLoanPolicyViolation::with(['employee'])
            ->where('status', 'open')
            ->orderByDesc('detected_at')
            ->limit(5)
            ->get()
            ->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'employee'    => $v->employee?->name,
                'detected_at' => $v->detected_at->toISOString(),
            ]);

        return Inertia::render('Modules/Finance/StaffLoan/Dashboard/Index', [
            'stats'          => $stats,
            'recentLoans'    => $recentLoans,
            'recentAdvances' => $recentAdvances,
            'violations'     => $violations,
            'totalOutstanding'=> $totalOutstanding,
        ]);
    }
}
