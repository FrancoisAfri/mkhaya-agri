<?php

namespace App\Modules\Finance\Executive\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Models\StaffLoanPolicyViolation;
use App\Modules\Finance\StaffLoan\Models\StaffLoanRepayment;
use App\Modules\Finance\StaffLoan\Services\StaffLoanAIService;
use App\Modules\Finance\Executive\Services\FinanceExecutiveOverviewService;
use Inertia\Inertia;
use Inertia\Response;

class ExecutiveFinanceDashboardController extends Controller
{
    public function __construct(
        private StaffLoanAIService $aiService,
        private FinanceExecutiveOverviewService $overview,
    ) {}

    public function index(): Response
    {
        // ── Petty Cash ─────────────────────────────────────────────────────────
        $pettyCashData = $this->getPettyCashDashboard();

        // ── Loans & Advances ───────────────────────────────────────────────────
        $loanData      = $this->getLoanAdvanceDashboard();

        // ── Cross-module risk ──────────────────────────────────────────────────
        $riskSummary   = $this->generateFinanceRiskSummary();

        // ── Spend trend (last 6 months) ────────────────────────────────────────
        $spendTrend    = $this->generateSpendTrendAnalysis();

        // ── Policy compliance ──────────────────────────────────────────────────
        $compliance    = $this->generatePolicyComplianceSummary();

        $widgets = [
            ['label' => 'Active Staff Loans',         'value' => $loanData['active_loans'],      'icon' => 'mdi-bank',             'color' => 'primary'],
            ['label' => 'Active Salary Advances',     'value' => $loanData['active_advances'],   'icon' => 'mdi-cash-fast',        'color' => 'info'],
            ['label' => 'Payroll Deduction Exposure', 'value' => 'R'.number_format($loanData['monthly_deduction_exposure'], 0), 'icon' => 'mdi-cash-minus', 'color' => 'deep-orange'],
            ['label' => 'Petty Cash Float Exposure',  'value' => 'R'.number_format($pettyCashData['total_float_exposure'], 0), 'icon' => 'mdi-wallet', 'color' => 'teal'],
            ['label' => 'Petty Cash Shortages',       'value' => $pettyCashData['floats_needing_replenishment'], 'icon' => 'mdi-bank-alert',   'color' => 'warning'],
            ['label' => 'Missing Receipts',           'value' => $pettyCashData['missing_receipts'], 'icon' => 'mdi-receipt-text-remove', 'color' => 'error'],
            ['label' => 'High-Risk Employees',        'value' => $loanData['high_deduction_employees'], 'icon' => 'mdi-account-alert', 'color' => 'error'],
            ['label' => 'Default Risk Loans',         'value' => $loanData['default_risk'],      'icon' => 'mdi-alert-circle',     'color' => $loanData['default_risk'] > 0 ? 'error' : 'success'],
            ['label' => 'Policy Violations (Total)',  'value' => $compliance['total_violations'], 'icon' => 'mdi-shield-alert',    'color' => $compliance['total_violations'] > 0 ? 'error' : 'success'],
            ['label' => 'Financial Wellness Risk',    'value' => $riskSummary['wellness_risk_count'], 'icon' => 'mdi-heart-pulse',  'color' => 'purple'],
            ['label' => 'Pending Finance Approvals',  'value' => $loanData['pending_approvals'], 'icon' => 'mdi-clock-alert',     'color' => 'orange'],
            ['label' => 'Overdue Repayments',         'value' => $loanData['overdue_repayments'],'icon' => 'mdi-calendar-remove',  'color' => $loanData['overdue_repayments'] > 0 ? 'error' : 'success'],
        ];

        // Cross-module finance overview — one card per ACTIVE finance module.
        $moduleFinance = $this->overview->cards();

        return Inertia::render('Modules/Finance/Executive/Dashboard/Index', [
            'widgets'       => $widgets,
            'moduleFinance' => $moduleFinance,
            'pettyCash'     => $pettyCashData,
            'loans'         => $loanData,
            'riskSummary'   => $riskSummary,
            'spendTrend'    => $spendTrend,
            'compliance'    => $compliance,
        ]);
    }

    public function getPettyCashDashboard(): array
    {
        $floats = PettyCashFloat::where('status', 'active')->get();

        return [
            'total_float_exposure'           => $floats->sum('float_limit'),
            'total_current_balance'          => $floats->sum('current_balance'),
            'floats_needing_replenishment'   => $floats->filter(fn($f) => $f->needsReplenishment())->count(),
            'missing_receipts'               => PettyCashRequest::where('status', 'issued')
                ->where('receipt_submitted', false)
                ->where('receipt_due_at', '<', now())
                ->count(),
            'pending_reconciliations'        => PettyCashReconciliation::where('status', 'submitted')->count(),
            'total_issued_this_month'        => PettyCashRequest::where('status', 'issued')
                ->whereMonth('issued_at', now()->month)
                ->sum('amount_issued'),
            'open_violations'                => PettyCashPolicyViolation::where('status', 'open')->count(),
        ];
    }

    public function getLoanAdvanceDashboard(): array
    {
        return [
            'active_loans'              => StaffLoan::where('status', 'active')->count(),
            'active_advances'           => SalaryAdvance::whereIn('status', ['approved', 'disbursed'])->count(),
            'total_outstanding'         => StaffLoan::where('status', 'active')->sum('outstanding_balance'),
            'monthly_deduction_exposure'=> StaffLoan::where('status', 'active')->sum('monthly_installment'),
            'pending_approvals'         => StaffLoan::where('status', 'pending')->count()
                                         + SalaryAdvance::where('status', 'pending')->count(),
            'default_risk'              => StaffLoan::where('status', 'active')
                ->whereHas('repayments', fn($q) => $q->where('status', 'overdue'))
                ->count(),
            'overdue_repayments'        => StaffLoanRepayment::where('status', 'overdue')
                ->whereHas('loan', fn($q) => $q->where('status', 'active'))
                ->count(),
            'high_deduction_employees'  => StaffLoan::where('status', 'active')
                ->selectRaw('employee_id, SUM(monthly_installment) as total')
                ->groupBy('employee_id')
                ->havingRaw('SUM(monthly_installment) > 5000')
                ->count(),
        ];
    }

    public function generateFinanceRiskSummary(): array
    {
        $wellnessRisk = StaffLoan::where('status', 'active')
            ->whereIn('risk_level', ['high', 'critical'])
            ->distinct('employee_id')
            ->count('employee_id');

        $openViolations = StaffLoanPolicyViolation::where('status', 'open')->count()
            + PettyCashPolicyViolation::where('status', 'open')->count();

        return [
            'wellness_risk_count' => $wellnessRisk,
            'open_violations'     => $openViolations,
            'critical_loans'      => StaffLoan::where('risk_level', 'critical')->where('status', 'active')->count(),
        ];
    }

    public function generateSpendTrendAnalysis(): array
    {
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $date  = now()->subMonths($i);
            $label = $date->format('M Y');

            $pettyCashIssued = PettyCashRequest::whereYear('issued_at', $date->year)
                ->whereMonth('issued_at', $date->month)
                ->sum('amount_issued');

            $loansIssued = StaffLoan::whereYear('disbursed_at', $date->year)
                ->whereMonth('disbursed_at', $date->month)
                ->sum('amount_approved');

            $advancesIssued = SalaryAdvance::whereYear('disbursed_at', $date->year)
                ->whereMonth('disbursed_at', $date->month)
                ->sum('amount_approved');

            $months[] = [
                'month'        => $label,
                'petty_cash'   => (float) $pettyCashIssued,
                'loans'        => (float) $loansIssued,
                'advances'     => (float) $advancesIssued,
                'total'        => (float) $pettyCashIssued + $loansIssued + $advancesIssued,
            ];
        }

        return $months;
    }

    public function generatePolicyComplianceSummary(): array
    {
        $pettyCashViolations = PettyCashPolicyViolation::count();
        $loanViolations      = StaffLoanPolicyViolation::count();
        $totalViolations     = $pettyCashViolations + $loanViolations;

        $resolvedPettyCash   = PettyCashPolicyViolation::where('status', 'resolved')->count();
        $resolvedLoans       = StaffLoanPolicyViolation::where('status', 'resolved')->count();
        $totalResolved       = $resolvedPettyCash + $resolvedLoans;

        $resolutionRate = $totalViolations > 0 ? round(($totalResolved / $totalViolations) * 100, 1) : 100;

        return [
            'total_violations'    => $totalViolations,
            'petty_cash_violations'=> $pettyCashViolations,
            'loan_violations'     => $loanViolations,
            'resolved'            => $totalResolved,
            'resolution_rate'     => $resolutionRate,
        ];
    }
}
