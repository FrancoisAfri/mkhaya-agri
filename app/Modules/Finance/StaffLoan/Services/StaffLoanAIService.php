<?php

namespace App\Modules\Finance\StaffLoan\Services;

use App\Modules\Finance\StaffLoan\Models\SalaryAdvance;
use App\Modules\Finance\StaffLoan\Models\StaffLoan;
use App\Modules\Finance\StaffLoan\Models\StaffLoanRepayment;

class StaffLoanAIService
{
    private const ADVANCE_LIMIT_PCT  = 0.30;
    private const LOAN_DEDUCTION_PCT = 0.25;
    private const HIGH_RISK_SCORE    = 70;

    public function calculateAffordabilityScore(int $employeeId, float $monthlyNetPay, float $proposedDeduction): array
    {
        $activeLoansDeduction = StaffLoan::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->sum('monthly_installment');

        $activeAdvances = SalaryAdvance::where('employee_id', $employeeId)
            ->whereIn('status', ['approved', 'disbursed'])
            ->sum('amount_approved');

        $totalExisting    = (float) $activeLoansDeduction + ($activeAdvances / 12);
        $totalProposed    = $totalExisting + $proposedDeduction;
        $deductionRatio   = $monthlyNetPay > 0 ? $totalProposed / $monthlyNetPay : 1;
        $score            = max(0, 100 - (int) ($deductionRatio * 200));
        $disposable       = $monthlyNetPay - $totalProposed;

        return [
            'score'              => $score,
            'deduction_ratio'    => round($deductionRatio * 100, 2),
            'disposable_income'  => $disposable,
            'existing_deductions'=> $totalExisting,
            'total_proposed'     => $totalProposed,
            'is_affordable'      => $deductionRatio <= self::LOAN_DEDUCTION_PCT,
        ];
    }

    public function detectFrequentAdvanceRequests(int $employeeId): array
    {
        $flags = [];

        $recentCount = SalaryAdvance::where('employee_id', $employeeId)
            ->where('created_at', '>=', now()->subMonths(3))
            ->whereIn('status', ['pending', 'approved', 'disbursed', 'settled'])
            ->count();

        if ($recentCount >= 3) {
            $flags[] = [
                'type'        => 'frequent_advances',
                'description' => "Employee has submitted {$recentCount} salary advance requests in the last 3 months.",
                'severity'    => $recentCount >= 5 ? 'high' : 'medium',
                'details'     => ['count' => $recentCount, 'window_months' => 3],
            ];
        }

        return $flags;
    }

    public function detectLoanDependencyRisk(int $employeeId): array
    {
        $flags = [];

        $activeLoans    = StaffLoan::where('employee_id', $employeeId)->where('status', 'active')->count();
        $lifetimeLoans  = StaffLoan::where('employee_id', $employeeId)->count();
        $lifetimeAdv    = SalaryAdvance::where('employee_id', $employeeId)->count();

        if ($activeLoans >= 2) {
            $flags[] = [
                'type'        => 'multiple_active_loans',
                'description' => "Employee has {$activeLoans} active loans simultaneously.",
                'severity'    => 'high',
            ];
        }

        if ($lifetimeLoans >= 5 || $lifetimeAdv >= 6) {
            $flags[] = [
                'type'        => 'loan_dependency',
                'description' => "Employee has a history of {$lifetimeLoans} loans and {$lifetimeAdv} advances — potential financial dependency.",
                'severity'    => 'medium',
            ];
        }

        return $flags;
    }

    public function detectHighDeductionBurden(int $employeeId, float $monthlyNetPay): array
    {
        $flags = [];

        if ($monthlyNetPay <= 0) {
            return $flags;
        }

        $totalDeductions = StaffLoan::where('employee_id', $employeeId)
            ->where('status', 'active')
            ->sum('monthly_installment');

        $ratio = $totalDeductions / $monthlyNetPay;

        if ($ratio > 0.40) {
            $flags[] = [
                'type'        => 'high_deduction_burden',
                'description' => sprintf('Total loan deductions are %.1f%% of monthly net pay — critically high.', $ratio * 100),
                'severity'    => 'critical',
                'details'     => ['ratio' => $ratio, 'total_deductions' => $totalDeductions],
            ];
        } elseif ($ratio > 0.25) {
            $flags[] = [
                'type'        => 'elevated_deductions',
                'description' => sprintf('Total loan deductions are %.1f%% of monthly net pay — above the 25%% policy threshold.', $ratio * 100),
                'severity'    => 'high',
                'details'     => ['ratio' => $ratio],
            ];
        }

        return $flags;
    }

    public function predictRepaymentDefaultRisk(StaffLoan $loan): array
    {
        $flags = [];

        $overdueCount = $loan->repayments()->where('status', 'overdue')->count();
        $partialCount = $loan->repayments()->where('status', 'partial')->count();

        if ($overdueCount >= 3) {
            $flags[] = [
                'type'        => 'default_risk',
                'description' => "Loan has {$overdueCount} overdue installments — high default probability.",
                'severity'    => 'critical',
            ];
        } elseif ($overdueCount >= 1) {
            $flags[] = [
                'type'        => 'overdue_installments',
                'description' => "{$overdueCount} installment(s) are overdue.",
                'severity'    => 'high',
            ];
        }

        if ($partialCount >= 2) {
            $flags[] = [
                'type'        => 'partial_payments',
                'description' => "{$partialCount} installments have only been partially paid.",
                'severity'    => 'medium',
            ];
        }

        return $flags;
    }

    public function calculateAdvanceRiskScore(SalaryAdvance $advance): array
    {
        $score = 0;
        $flags = [];

        $frequentFlags = $this->detectFrequentAdvanceRequests($advance->employee_id);
        $dependencyFlags = $this->detectLoanDependencyRisk($advance->employee_id);

        foreach ($frequentFlags as $flag) {
            $score += $flag['severity'] === 'high' ? 30 : 20;
            $flags[] = $flag;
        }

        foreach ($dependencyFlags as $flag) {
            $score += $flag['severity'] === 'high' ? 25 : 15;
            $flags[] = $flag;
        }

        if ($advance->monthly_net_pay > 0) {
            $pct = ($advance->amount_requested / $advance->monthly_net_pay);
            if ($pct > 0.30) {
                $score += 30;
                $flags[] = [
                    'type'        => 'exceeds_advance_limit',
                    'description' => sprintf('Advance is %.1f%% of net pay — exceeds 30%% limit.', $pct * 100),
                    'severity'    => 'high',
                ];
            }

            $highDeductFlags = $this->detectHighDeductionBurden($advance->employee_id, (float) $advance->monthly_net_pay);
            foreach ($highDeductFlags as $flag) {
                $score += 20;
                $flags[] = $flag;
            }
        }

        $score = min(100, $score);
        $level = match (true) {
            $score >= 75 => 'critical',
            $score >= 50 => 'high',
            $score >= 25 => 'medium',
            default      => 'low',
        };

        return ['score' => $score, 'level' => $level, 'flags' => $flags];
    }

    public function generateLoanRecommendation(int $employeeId, float $requestedAmount, float $monthlyNetPay): array
    {
        $affordability = $this->calculateAffordabilityScore($employeeId, $monthlyNetPay, $requestedAmount / 24);
        $dependency    = $this->detectLoanDependencyRisk($employeeId);

        $recommendation = 'approve';
        $reasons        = [];

        if (!$affordability['is_affordable']) {
            $recommendation = 'reject';
            $reasons[] = "Proposed deductions exceed 25% of net pay ({$affordability['deduction_ratio']}%).";
        }

        if (!empty($dependency)) {
            $recommendation = $recommendation === 'approve' ? 'review' : 'reject';
            $reasons[] = 'Employee shows signs of loan dependency.';
        }

        if ($affordability['deduction_ratio'] > 20) {
            $recommendation = $recommendation === 'approve' ? 'review' : $recommendation;
            $reasons[] = 'Deduction burden is elevated — recommend senior review.';
        }

        return [
            'recommendation'  => $recommendation,
            'reasons'         => $reasons,
            'affordability'   => $affordability,
            'max_safe_amount' => round($monthlyNetPay * self::LOAN_DEDUCTION_PCT * 24, -2),
        ];
    }

    public function generateFinancialWellnessSignal(int $employeeId, float $monthlyNetPay): array
    {
        $activeLoans   = StaffLoan::where('employee_id', $employeeId)->where('status', 'active')->count();
        $totalDeduct   = (float) StaffLoan::where('employee_id', $employeeId)->where('status', 'active')->sum('monthly_installment');
        $advanceCount  = SalaryAdvance::where('employee_id', $employeeId)->whereIn('status', ['approved', 'disbursed'])->count();
        $overdueCount  = StaffLoanRepayment::whereHas('loan', fn($q) => $q->where('employee_id', $employeeId))
            ->where('status', 'overdue')->count();

        $deductionRatio = $monthlyNetPay > 0 ? $totalDeduct / $monthlyNetPay : 0;

        $score = 100;
        $score -= min(40, (int) ($deductionRatio * 160));
        $score -= $overdueCount * 10;
        $score -= $activeLoans > 1 ? ($activeLoans - 1) * 8 : 0;
        $score -= $advanceCount * 5;
        $score  = max(0, $score);

        $signal = match (true) {
            $score >= 80 => 'healthy',
            $score >= 60 => 'moderate',
            $score >= 40 => 'at_risk',
            default      => 'critical',
        };

        return [
            'score'           => $score,
            'signal'          => $signal,
            'deduction_ratio' => round($deductionRatio * 100, 2),
            'active_loans'    => $activeLoans,
            'active_advances' => $advanceCount,
            'overdue_count'   => $overdueCount,
        ];
    }
}
