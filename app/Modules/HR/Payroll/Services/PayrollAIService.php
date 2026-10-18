<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollAIService
{
    // ── Public AI gateway ─────────────────────────────────────────────────────

    public function askPayrollAI(string $question, ?int $runId = null): string
    {
        $user = auth()->user();
        $q    = strtolower($question);

        $isHR      = $user?->hasAnyRole(['payroll officer', 'hr manager', 'super-admin']);
        $isFinance = $user?->hasAnyRole(['finance manager', 'super-admin']);
        $isExec    = $user?->hasAnyRole(['executive', 'ceo', 'cfo', 'super-admin']);
        $canViewAll = $isHR || $isFinance || $isExec;

        $contains = fn (array $kw) => collect($kw)->some(fn ($k) => str_contains($q, $k));

        $run = $runId
            ? PayrollRun::find($runId)
            : PayrollRun::where('status', 'released')->latest('period_start')->first();

        if (!$run) {
            return "No released payroll run found. Please complete a payroll run first.";
        }

        // Anomaly / suspicious / risk
        if ($contains(['suspicious', 'anomal', 'fraud', 'flag', 'irregular', 'ghost', 'leakage', 'risk', 'unusual payment'])) {
            if (!$canViewAll) return "You don't have permission to view anomaly or fraud data.";
            return $this->summariseAnomalies($run);
        }

        // Cost increase / month-on-month
        if ($contains(['increase', 'grew', 'more expensive', 'payroll cost', 'cost change', 'higher this month', 'lower this month', 'went up', 'went down', 'why did payroll', 'cost more', 'cost less'])) {
            if (!$canViewAll) return "You don't have permission to view payroll cost data.";
            return $this->explainPayrollCostChange($run);
        }

        // Department / budget
        if ($contains(['department', 'division', 'budget', 'exceeded', 'over budget', 'unit', 'cost by dept', 'dept cost'])) {
            if (!$isFinance && !$isExec) return "You don't have permission to view department budget data.";
            return $this->answerDepartmentCostQuestion($run);
        }

        // Overtime
        if ($contains(['overtime', 'extra hours', 'unusual overtime', ' ot ', 'overworked', 'time and a half'])) {
            if (!$canViewAll) return "You don't have permission to view overtime data.";
            return $this->answerOvertimeQuestion($run);
        }

        // Employee net pay change (admin view)
        if ($contains(['net pay', "employee's pay", 'pay change', 'pay changed', 'why did this employee', 'employee net'])) {
            if ($isHR || $isExec || $isFinance) {
                return "To analyse a specific employee's net pay change, open their payslip via Payroll → Runs → [Run] → Payslips, then use the AI Explanation panel.";
            }
        }

        // Employee asking about own pay
        if ($contains(['my pay', 'my net', 'my payslip', 'my salary', 'take home', 'why is my'])) {
            $payslip = DB::table('payslips')
                ->where('employee_id', $user?->id)
                ->where('payroll_run_id', $run->id)
                ->first();

            if ($payslip) {
                $model = Payslip::find($payslip->id);
                if ($model) return $this->answerPayslipQuestion($model, $question);
            }
            return "No payslip found for you in run {$run->ref_number}. Contact payroll if this is unexpected.";
        }

        // Executive briefing / overview
        if ($contains(['briefing', 'executive', 'overview', 'health', 'status', 'summary', 'how is payroll', 'payroll summary'])) {
            if (!$canViewAll) return "You don't have permission to view executive payroll summaries.";
            return $this->generateExecutivePayrollBriefing($run);
        }

        // Default: return briefing for privileged, prompt for employees
        if ($canViewAll) {
            return $this->generateExecutivePayrollBriefing($run);
        }

        return "I can assist with payroll questions based on your access level. Try asking about your payslip, deductions, tax, or leave impact on pay.";
    }

    // ── Phase 8 methods ───────────────────────────────────────────────────────

    public function explainPayslipToEmployee(Payslip $payslip): array
    {
        return $this->explainPayslip($payslip);
    }

    public function explainPayslip(Payslip $payslip): array
    {
        $payslip->loadMissing('earningsLines');

        return [
            'gross_pay_explanation'    => $this->explainGrossPay($payslip),
            'deductions_explanation'   => $this->explainDeductions($payslip),
            'tax_explanation'          => $this->explainTax($payslip),
            'overtime_explanation'     => $this->explainOvertime($payslip),
            'leave_impact_explanation' => $this->explainLeaveImpactOnPay($payslip),
            'net_pay_explanation'      => "Your net take-home pay is R" . number_format((float) $payslip->net_pay, 2) . " after all deductions.",
            'changes_from_last_month'  => $this->buildChangesFromLastMonth($payslip),
            'flags'                    => $this->buildPayslipFlags($payslip),
        ];
    }

    public function explainGrossPay(Payslip $payslip): string
    {
        $payslip->loadMissing('earningsLines');

        $basic         = (float) $payslip->basic_salary;
        $gross         = (float) $payslip->gross_pay;
        $nonBasicLines = $payslip->earningsLines->filter(fn ($l) => $l->line_type->value !== 'basic_salary' && !$l->line_type->isDeduction());
        $count         = $nonBasicLines->count();

        return "Gross pay of R" . number_format($gross, 2) . " includes basic salary R" . number_format($basic, 2) . " plus {$count} overtime/allowance line(s).";
    }

    public function explainDeductions(Payslip $payslip): string
    {
        $lines = [];
        if ((float) $payslip->paye > 0)    $lines[] = "PAYE: R" . number_format((float) $payslip->paye, 2);
        if ((float) $payslip->uif > 0)     $lines[] = "UIF: R" . number_format((float) $payslip->uif, 2);
        if ((float) $payslip->pension > 0) $lines[] = "Pension: R" . number_format((float) $payslip->pension, 2);

        $other = (float) $payslip->total_deductions - (float) $payslip->paye - (float) $payslip->uif - (float) $payslip->pension;
        if ($other > 0) $lines[] = "Other deductions (loans/advances): R" . number_format($other, 2);

        if (empty($lines)) return "No deductions applied this period.";

        return "Your deductions this period: " . implode(', ', $lines) . ". Total: R" . number_format((float) $payslip->total_deductions, 2) . ".";
    }

    public function explainTax(Payslip $payslip): string
    {
        $paye  = (float) $payslip->paye;
        $gross = (float) $payslip->gross_pay;

        if ($paye <= 0) {
            return $gross <= 7_979.17
                ? "No PAYE deducted — gross pay is below the R7,979.17/month threshold (R95,750/year)."
                : "No PAYE deducted this period. Please verify with payroll if this is expected.";
        }

        return "PAYE of R" . number_format($paye, 2) . " is calculated at 18% on taxable income above the annual threshold of R95,750 (R7,979.17/month). "
            . "Annual equivalent gross: R" . number_format($gross * 12, 2) . ".";
    }

    public function explainOvertime(Payslip $payslip): string
    {
        $payslip->loadMissing('earningsLines');

        $overtimeLines = $payslip->earningsLines->filter(fn ($l) => $l->line_type->value === 'overtime');
        $totalAmount   = $overtimeLines->sum('amount');
        $totalHours    = $overtimeLines->sum('quantity');

        if ($overtimeLines->isEmpty()) return "No overtime was recorded for this period.";

        return "Overtime of R" . number_format((float) $totalAmount, 2) . " was paid for " . number_format((float) $totalHours, 1) . " hours across {$overtimeLines->count()} overtime line(s).";
    }

    public function explainLeaveImpactOnPay(Payslip $payslip): string
    {
        try {
            $hrPerson = DB::table('hr_people')
                ->where('user_id', $payslip->employee_id)
                ->select('id')
                ->first();

            if (!$hrPerson) return "Leave information could not be retrieved.";

            $leaves = DB::table('leave_requests')
                ->where('employee_id', $hrPerson->id)
                ->where('status', 'approved')
                ->whereBetween('start_date', [$payslip->period_start, $payslip->period_end])
                ->get();

            if ($leaves->isEmpty()) return "No approved leave was recorded during this pay period.";

            $descriptions = $leaves->map(fn ($l) => "{$l->start_date} to {$l->end_date}")->implode('; ');
            return "Leave was recorded in this period: {$descriptions}. Check if unpaid leave affected your gross pay.";
        } catch (\Throwable $e) {
            Log::error('explainLeaveImpactOnPay error: ' . $e->getMessage());
            return "Leave information could not be retrieved.";
        }
    }

    public function answerPayslipQuestion(Payslip $payslip, string $question): string
    {
        $q = strtolower($question);

        if (str_contains($q, 'paye') || str_contains($q, 'tax') || str_contains($q, 'income tax')) {
            return $this->explainTax($payslip);
        }
        if (str_contains($q, 'overtime') || str_contains($q, 'extra hours')) {
            return $this->explainOvertime($payslip);
        }
        if (str_contains($q, 'deduction') || str_contains($q, 'deducted')) {
            return $this->explainDeductions($payslip);
        }
        if (str_contains($q, 'leave') || str_contains($q, 'absence') || str_contains($q, 'annual leave')) {
            return $this->explainLeaveImpactOnPay($payslip);
        }
        if (str_contains($q, 'gross') || str_contains($q, 'allowance') || str_contains($q, 'total earnings')) {
            return $this->explainGrossPay($payslip);
        }
        if (str_contains($q, 'net') || str_contains($q, 'take home') || str_contains($q, 'take-home') || str_contains($q, 'get paid')) {
            $explanation = $this->explainPayslip($payslip);
            return $explanation['net_pay_explanation'];
        }
        if (str_contains($q, 'uif') || str_contains($q, 'unemployment')) {
            $uif = (float) $payslip->uif;
            return $uif > 0
                ? "UIF of R" . number_format($uif, 2) . " is deducted at 1% of basic salary, capped at R177.12/month."
                : "No UIF was deducted this period.";
        }
        if (str_contains($q, 'pension') || str_contains($q, 'retirement') || str_contains($q, 'provident')) {
            $pension = (float) $payslip->pension;
            return $pension > 0
                ? "Pension/provident fund contribution of R" . number_format($pension, 2) . " was deducted this period."
                : "No pension or retirement fund deduction was applied this period.";
        }
        if (str_contains($q, 'last month') || str_contains($q, 'previous') || str_contains($q, 'change') || str_contains($q, 'different')) {
            return $this->buildChangesFromLastMonth($payslip);
        }
        if (str_contains($q, 'basic') || str_contains($q, 'base salary') || str_contains($q, 'basic salary')) {
            return "Your basic salary this period is R" . number_format((float) $payslip->basic_salary, 2) . ".";
        }

        $explanation = $this->explainPayslip($payslip);
        return implode(' ', array_filter([
            $explanation['gross_pay_explanation'],
            $explanation['deductions_explanation'],
            $explanation['net_pay_explanation'],
        ]));
    }

    // ── Phase 9 methods ───────────────────────────────────────────────────────

    public function generatePayrollInsights(PayrollRun $run): array
    {
        try {
            $topEarners = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->orderByDesc('gross_pay')
                ->limit(3)
                ->select('employee_id', 'gross_pay', 'net_pay')
                ->get()->toArray();

            $highestDeductions = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->orderByDesc('total_deductions')
                ->limit(3)
                ->select('employee_id', 'total_deductions')
                ->get()->toArray();

            $anomalyCount  = DB::table('payroll_anomalies')->where('run_id', $run->id)->count();
            $criticalCount = DB::table('payroll_anomalies')->where('run_id', $run->id)->where('severity', 'critical')->count();

            $riskLevel = match (true) {
                $criticalCount > 0 => 'critical',
                $anomalyCount > 10 => 'high',
                $anomalyCount > 5  => 'medium',
                default            => 'low',
            };

            return [
                'top_earners'        => $topEarners,
                'highest_deductions' => $highestDeductions,
                'anomaly_count'      => $anomalyCount,
                'risk_level'         => $riskLevel,
                'safe_to_release'    => $criticalCount === 0 && $anomalyCount < 5,
            ];
        } catch (\Throwable $e) {
            Log::error('generatePayrollInsights error: ' . $e->getMessage());
            return ['top_earners' => [], 'highest_deductions' => [], 'anomaly_count' => 0, 'risk_level' => 'unknown', 'safe_to_release' => false];
        }
    }

    public function generateExecutivePayrollBriefing(PayrollRun $run): string
    {
        $insights      = $this->generatePayrollInsights($run);
        $periodStart   = $run->period_start?->format('d M Y') ?? '—';
        $periodEnd     = $run->period_end?->format('d M Y') ?? '—';
        $totalNet      = number_format((float) $run->total_net, 2);
        $employeeCount = (int) $run->employee_count;
        $name          = $run->name ?? $run->ref_number ?? "#{$run->id}";

        return "Payroll run {$name} covers {$periodStart} to {$periodEnd} with {$employeeCount} employees. "
            . "Total cost: R{$totalNet}. Risk: {$insights['risk_level']}. {$insights['anomaly_count']} anomalies detected."
            . ($insights['safe_to_release'] ? " Payroll is safe to release." : " Review anomalies before releasing.");
    }

    public function answerEmployeePayrollQuestion(int $employeeId, string $question): string
    {
        try {
            $payslip = DB::table('payslips')->where('employee_id', $employeeId)->orderByDesc('created_at')->first();
            if (!$payslip) return "No payslip found for employee {$employeeId}.";
            $model = Payslip::find($payslip->id);
            return $model ? $this->answerPayslipQuestion($model, $question) : "Payslip data could not be loaded.";
        } catch (\Throwable $e) {
            Log::error('answerEmployeePayrollQuestion error: ' . $e->getMessage());
            return "Unable to retrieve payroll information at this time.";
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summariseAnomalies(PayrollRun $run): string
    {
        try {
            $total    = DB::table('payroll_anomalies')->where('run_id', $run->id)->count();
            $critical = DB::table('payroll_anomalies')->where('run_id', $run->id)->where('severity', 'critical')->count();
            $high     = DB::table('payroll_anomalies')->where('run_id', $run->id)->where('severity', 'high')->count();
            $open     = DB::table('payroll_anomalies')->where('run_id', $run->id)->where('status', 'open')->count();

            if ($total === 0) {
                return "No anomalies detected for run {$run->ref_number}. Payroll appears clean.";
            }

            return "Run {$run->ref_number} has {$total} anomaly(s): {$critical} critical, {$high} high severity, {$open} still open. "
                . "Review them under Payroll → Anomalies.";
        } catch (\Throwable $e) {
            Log::error('summariseAnomalies error: ' . $e->getMessage());
            return "Anomaly data could not be retrieved.";
        }
    }

    private function explainPayrollCostChange(PayrollRun $run): string
    {
        try {
            $prev = DB::table('payroll_runs')
                ->where('status', 'released')
                ->where('period_start', '<', $run->period_start)
                ->orderByDesc('period_start')
                ->first();

            $current = (float) $run->total_net;

            if (!$prev) {
                return "This is the first released payroll run. Total net cost: R" . number_format($current, 2) . ".";
            }

            $previous = (float) $prev->total_net;
            $diff     = $current - $previous;
            $pct      = $previous > 0 ? round(($diff / $previous) * 100, 2) : 0;
            $dir      = $diff >= 0 ? 'increased' : 'decreased';

            return "Payroll {$dir} by R" . number_format(abs($diff), 2) . " (" . abs($pct) . "%) "
                . "from R" . number_format($previous, 2) . " (run {$prev->ref_number}) "
                . "to R" . number_format($current, 2) . " (run {$run->ref_number}).";
        } catch (\Throwable $e) {
            Log::error('explainPayrollCostChange error: ' . $e->getMessage());
            return "Payroll cost comparison data could not be retrieved.";
        }
    }

    private function answerDepartmentCostQuestion(PayrollRun $run): string
    {
        try {
            $depts = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.division_level_1')
                ->select('hp.division_level_1 as dept', DB::raw('SUM(ps.gross_pay) as total_gross'))
                ->groupBy('hp.division_level_1')
                ->orderByDesc('total_gross')
                ->limit(5)
                ->get();

            if ($depts->isEmpty()) {
                return "No department breakdown available for run {$run->ref_number}.";
            }

            $lines = $depts->map(fn ($d) => "{$d->dept}: R" . number_format((float) $d->total_gross, 2))->implode('; ');
            return "Top departments by gross payroll cost for run {$run->ref_number}: {$lines}.";
        } catch (\Throwable $e) {
            Log::error('answerDepartmentCostQuestion error: ' . $e->getMessage());
            return "Department cost data could not be retrieved.";
        }
    }

    private function answerOvertimeQuestion(PayrollRun $run): string
    {
        try {
            $topOT = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'overtime')
                ->select(
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) as full_name"),
                    DB::raw('SUM(pel.amount) as total_ot'),
                    DB::raw('SUM(pel.quantity) as total_hrs'),
                )
                ->groupBy('hp.first_name', 'hp.surname')
                ->orderByDesc('total_ot')
                ->limit(5)
                ->get();

            if ($topOT->isEmpty()) {
                return "No overtime recorded for run {$run->ref_number}.";
            }

            $total = $topOT->sum('total_ot');
            $lines = $topOT->map(fn ($r) => "{$r->full_name}: R" . number_format((float) $r->total_ot, 2) . " ({$r->total_hrs} hrs)")->implode('; ');
            return "Total overtime for run {$run->ref_number}: R" . number_format((float) $total, 2) . ". Top earners: {$lines}.";
        } catch (\Throwable $e) {
            Log::error('answerOvertimeQuestion error: ' . $e->getMessage());
            return "Overtime data could not be retrieved.";
        }
    }

    private function buildChangesFromLastMonth(Payslip $payslip): string
    {
        try {
            $previous = DB::table('payslips as ps')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('ps.employee_id', $payslip->employee_id)
                ->where('pr.period_start', '<', $payslip->period_start)
                ->orderByDesc('pr.period_start')
                ->select('ps.gross_pay', 'ps.net_pay', 'ps.basic_salary')
                ->first();

            if (!$previous) return "No previous payslip to compare.";

            $grossDiff = round((float) $payslip->gross_pay - (float) $previous->gross_pay, 2);
            $netDiff   = round((float) $payslip->net_pay - (float) $previous->net_pay, 2);

            $grossLabel = $grossDiff >= 0 ? "+R{$grossDiff}" : "-R" . abs($grossDiff);
            $netLabel   = $netDiff >= 0 ? "+R{$netDiff}" : "-R" . abs($netDiff);

            return "Compared to last month: Gross {$grossLabel}, Net {$netLabel}.";
        } catch (\Throwable) {
            return "No previous payslip data available.";
        }
    }

    private function buildPayslipFlags(Payslip $payslip): array
    {
        $flags = [];
        if ((float) $payslip->net_pay < 0) {
            $flags[] = "Net pay is negative.";
        }
        if ((float) $payslip->paye <= 0 && (float) $payslip->gross_pay > 7_979.17) {
            $flags[] = "No PAYE deducted despite gross pay above monthly threshold.";
        }
        if ((float) $payslip->total_deductions > (float) $payslip->gross_pay) {
            $flags[] = "Total deductions exceed gross pay.";
        }
        return $flags;
    }
}
