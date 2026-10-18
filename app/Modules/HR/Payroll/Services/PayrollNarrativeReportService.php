<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollNarrativeReportService
{
    private const COMPLIANCE_TYPES = [
        'overtime_law_violation', 'minimum_wage_violation', 'leave_compliance_issue',
        'contract_payroll_mismatch', 'tax_compliance_issue', 'overtime_rate_violation',
    ];

    private const LEAKAGE_TYPES = [
        'duplicate_payment', 'ghost_employee', 'salary_overpayment',
        'allowance_overpayment', 'overtime_leakage',
    ];

    // ── Master report ─────────────────────────────────────────────────────────

    public function generatePayrollNarrativeReport(PayrollRun $run): array
    {
        return [
            'run'        => $this->runMeta($run),
            'executive'  => $this->generateExecutivePayrollSummary($run),
            'departments'=> $this->generateDepartmentPayrollNarrative($run),
            'variance'   => $this->generateVarianceExplanation($run),
            'risk'       => $this->generatePayrollRiskReport($run),
            'compliance' => $this->generatePayrollComplianceSummary($run),
            'leakage'    => $this->generatePayrollLeakageReport($run),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ── Section reports ───────────────────────────────────────────────────────

    public function generateExecutivePayrollSummary(PayrollRun $run): array
    {
        try {
            $prev = PayrollRun::where('status', 'released')
                ->where('period_start', '<', $run->period_start)
                ->latest('period_start')
                ->first();

            $grossVar = $prev && (float) $prev->total_gross > 0
                ? round(((float) $run->total_gross - (float) $prev->total_gross) / (float) $prev->total_gross * 100, 2)
                : null;

            $netVar = $prev && (float) $prev->total_net > 0
                ? round(((float) $run->total_net - (float) $prev->total_net) / (float) $prev->total_net * 100, 2)
                : null;

            $openAnomalies = DB::table('payroll_anomalies')
                ->where('run_id', $run->id)
                ->where('status', 'open')
                ->count();

            $criticalCount = DB::table('payroll_anomalies')
                ->where('run_id', $run->id)
                ->where('severity', 'critical')
                ->count();

            $narrative = "Payroll run {$run->ref_number} covers the period " . $run->period_start->format('d M Y') . " to " . $run->period_end->format('d M Y') . ". ";
            $narrative .= "Total gross pay is R" . number_format((float) $run->total_gross, 2) . " and total net pay is R" . number_format((float) $run->total_net, 2) . " for {$run->employee_count} employees. ";

            if ($grossVar !== null) {
                $dir       = $grossVar >= 0 ? 'increased' : 'decreased';
                $narrative .= "Gross payroll has {$dir} by " . abs($grossVar) . "% compared to the previous run. ";
            }

            if ($openAnomalies > 0) {
                $narrative .= "There are {$openAnomalies} open anomaly flag(s)";
                if ($criticalCount > 0) {
                    $narrative .= " including {$criticalCount} critical item(s) requiring immediate attention";
                }
                $narrative .= ". ";
            } else {
                $narrative .= "No open anomalies were detected. ";
            }

            return [
                'narrative'      => $narrative,
                'total_gross'    => (float) $run->total_gross,
                'total_net'      => (float) $run->total_net,
                'total_deductions' => (float) $run->total_deductions,
                'employee_count' => (int) $run->employee_count,
                'gross_variance' => $grossVar,
                'net_variance'   => $netVar,
                'open_anomalies' => $openAnomalies,
                'critical_count' => $criticalCount,
                'prev_run'       => $prev ? ['ref_number' => $prev->ref_number, 'period_start' => $prev->period_start->toDateString()] : null,
            ];
        } catch (\Throwable $e) {
            Log::error('generateExecutivePayrollSummary error: ' . $e->getMessage());
            return ['narrative' => 'Unable to generate executive summary.', 'error' => true];
        }
    }

    public function generateDepartmentPayrollNarrative(PayrollRun $run): array
    {
        try {
            $depts = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->where('ps.payroll_run_id', $run->id)
                ->whereNotNull('hp.division_level_1')
                ->select(
                    'hp.division_level_1 as department',
                    DB::raw('COUNT(DISTINCT ps.employee_id) as headcount'),
                    DB::raw('SUM(ps.gross_pay) as total_gross'),
                    DB::raw('SUM(ps.net_pay) as total_net'),
                    DB::raw('AVG(ps.gross_pay) as avg_gross'),
                )
                ->groupBy('hp.division_level_1')
                ->orderByDesc('total_gross')
                ->get();

            $grandGross = $depts->sum('total_gross') ?: 1;

            $sections = $depts->map(fn ($d) => [
                'department'  => $d->department,
                'headcount'   => (int) $d->headcount,
                'total_gross' => round((float) $d->total_gross, 2),
                'total_net'   => round((float) $d->total_net, 2),
                'avg_gross'   => round((float) $d->avg_gross, 2),
                'share_pct'   => round((float) $d->total_gross / $grandGross * 100, 1),
                'narrative'   => "{$d->department}: {$d->headcount} employee(s), total gross R" . number_format((float) $d->total_gross, 2)
                    . " (" . round((float) $d->total_gross / $grandGross * 100, 1) . "% of payroll), average R" . number_format((float) $d->avg_gross, 2) . ".",
            ])->toArray();

            return [
                'sections'   => $sections,
                'dept_count' => count($sections),
                'narrative'  => count($sections) . " department(s) processed. The largest payroll cost is " . ($sections[0]['department'] ?? 'N/A') . " at R" . number_format($sections[0]['total_gross'] ?? 0, 2) . ".",
            ];
        } catch (\Throwable $e) {
            Log::error('generateDepartmentPayrollNarrative error: ' . $e->getMessage());
            return ['sections' => [], 'narrative' => 'Unable to generate department narrative.', 'error' => true];
        }
    }

    public function generateVarianceExplanation(PayrollRun $run): array
    {
        try {
            $prev = PayrollRun::where('status', 'released')
                ->where('period_start', '<', $run->period_start)
                ->latest('period_start')
                ->first();

            if (!$prev) {
                return ['narrative' => "No prior released run found for comparison.", 'items' => [], 'has_prev' => false];
            }

            $factors = [];

            // Headcount change
            $hcDiff = (int) $run->employee_count - (int) $prev->employee_count;
            if ($hcDiff !== 0) {
                $dir      = $hcDiff > 0 ? 'increased' : 'decreased';
                $factors[] = ['factor' => 'Headcount', 'change' => $hcDiff, 'narrative' => "Headcount {$dir} by " . abs($hcDiff) . " employee(s)."];
            }

            // Gross pay change
            $grossDiff = (float) $run->total_gross - (float) $prev->total_gross;
            if (abs($grossDiff) > 100) {
                $dir      = $grossDiff > 0 ? 'increased' : 'decreased';
                $factors[] = ['factor' => 'Gross Pay', 'change' => round($grossDiff, 2), 'narrative' => "Total gross pay {$dir} by R" . number_format(abs($grossDiff), 2) . "."];
            }

            // Deductions change
            $dedDiff = (float) $run->total_deductions - (float) $prev->total_deductions;
            if (abs($dedDiff) > 100) {
                $dir      = $dedDiff > 0 ? 'increased' : 'decreased';
                $factors[] = ['factor' => 'Deductions', 'change' => round($dedDiff, 2), 'narrative' => "Total deductions {$dir} by R" . number_format(abs($dedDiff), 2) . "."];
            }

            // Overtime change
            $otCurr = (float) DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)->where('pel.line_type', 'overtime')
                ->sum('pel.amount');

            $otPrev = (float) DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $prev->id)->where('pel.line_type', 'overtime')
                ->sum('pel.amount');

            if (abs($otCurr - $otPrev) > 100) {
                $dir      = $otCurr > $otPrev ? 'increased' : 'decreased';
                $factors[] = ['factor' => 'Overtime', 'change' => round($otCurr - $otPrev, 2), 'narrative' => "Overtime cost {$dir} by R" . number_format(abs($otCurr - $otPrev), 2) . " (from R" . number_format($otPrev, 2) . " to R" . number_format($otCurr, 2) . ")."];
            }

            $netDiff = (float) $run->total_net - (float) $prev->total_net;
            $narrative = empty($factors)
                ? "Payroll run {$run->ref_number} is broadly consistent with the previous run {$prev->ref_number} — no material variances detected."
                : "Compared to run {$prev->ref_number}: " . implode(' ', array_column($factors, 'narrative'));

            return [
                'narrative'    => $narrative,
                'items'        => $factors,
                'net_diff'     => round($netDiff, 2),
                'gross_diff'   => round($grossDiff, 2),
                'prev_run'     => ['ref_number' => $prev->ref_number, 'period_start' => $prev->period_start->toDateString()],
                'has_prev'     => true,
            ];
        } catch (\Throwable $e) {
            Log::error('generateVarianceExplanation error: ' . $e->getMessage());
            return ['narrative' => 'Unable to generate variance explanation.', 'items' => [], 'has_prev' => false, 'error' => true];
        }
    }

    public function generatePayrollRiskReport(PayrollRun $run): array
    {
        try {
            $anomalies = DB::table('payroll_anomalies')
                ->where('run_id', $run->id)
                ->select('anomaly_type', 'severity', 'status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('anomaly_type', 'severity', 'status')
                ->get();

            $risks = DB::table('payroll_change_risks as pcr')
                ->join('payroll_change_logs as pcl', 'pcl.id', '=', 'pcr.change_log_id')
                ->where('pcl.run_id', $run->id)
                ->select('pcr.risk_type', 'pcr.severity', 'pcr.status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('pcr.risk_type', 'pcr.severity', 'pcr.status')
                ->get();

            $totalAnomalies = $anomalies->sum('cnt');
            $criticalItems  = $anomalies->where('severity', 'critical')->sum('cnt');
            $openItems      = $anomalies->where('status', 'open')->sum('cnt');
            $totalRisks     = $risks->sum('cnt');

            $narrative = "Run {$run->ref_number} has {$totalAnomalies} total anomaly flag(s)";
            if ($criticalItems > 0) {
                $narrative .= " including {$criticalItems} critical item(s)";
            }
            $narrative .= ". {$openItems} item(s) remain open. ";

            if ($totalRisks > 0) {
                $narrative .= "{$totalRisks} change risk(s) were also flagged for this run.";
            } else {
                $narrative .= "No change risks were flagged.";
            }

            return [
                'narrative'       => $narrative,
                'anomaly_summary' => $anomalies->values()->toArray(),
                'risk_summary'    => $risks->values()->toArray(),
                'total_anomalies' => $totalAnomalies,
                'critical_items'  => $criticalItems,
                'open_items'      => $openItems,
                'total_risks'     => $totalRisks,
            ];
        } catch (\Throwable $e) {
            Log::error('generatePayrollRiskReport error: ' . $e->getMessage());
            return ['narrative' => 'Unable to generate risk report.', 'error' => true];
        }
    }

    public function generatePayrollComplianceSummary(PayrollRun $run): array
    {
        try {
            $violations = DB::table('payroll_anomalies')
                ->where('run_id', $run->id)
                ->whereIn('anomaly_type', self::COMPLIANCE_TYPES)
                ->select('anomaly_type', 'severity', 'status', 'employee_id', 'description')
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->get();

            $byType = $violations->groupBy('anomaly_type')
                ->map(fn ($group, $type) => [
                    'type'      => $type,
                    'count'     => $group->count(),
                    'open'      => $group->where('status', 'open')->count(),
                    'resolved'  => $group->where('status', 'resolved')->count(),
                    'employees' => $group->pluck('employee_id')->unique()->count(),
                ])->values()->toArray();

            $total    = $violations->count();
            $open     = $violations->where('status', 'open')->count();
            $critical = $violations->where('severity', 'critical')->count();

            $narrative = $total === 0
                ? "No compliance violations detected for run {$run->ref_number}. All checks passed."
                : "Run {$run->ref_number} has {$total} compliance violation(s), of which {$open} remain open. " . ($critical > 0 ? "{$critical} are critical — immediate remediation is required." : "No critical violations.");

            return [
                'narrative'  => $narrative,
                'by_type'    => $byType,
                'total'      => $total,
                'open'       => $open,
                'critical'   => $critical,
                'violations' => $violations->map(fn ($v) => [
                    'type'        => $v->anomaly_type,
                    'severity'    => $v->severity,
                    'status'      => $v->status,
                    'employee_id' => $v->employee_id,
                    'description' => $v->description,
                ])->values()->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error('generatePayrollComplianceSummary error: ' . $e->getMessage());
            return ['narrative' => 'Unable to generate compliance summary.', 'error' => true];
        }
    }

    public function generatePayrollLeakageReport(PayrollRun $run): array
    {
        try {
            $leakage = DB::table('payroll_anomalies')
                ->where('run_id', $run->id)
                ->whereIn('anomaly_type', self::LEAKAGE_TYPES)
                ->select('anomaly_type', 'severity', 'status', 'employee_id', 'description', 'metadata')
                ->get();

            $estimatedLoss = $leakage->sum(function ($row) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                return (float) ($meta['estimated_loss'] ?? $meta['overpayment_amount'] ?? 0);
            });

            $byType = $leakage->groupBy('anomaly_type')
                ->map(fn ($group, $type) => [
                    'type'      => $type,
                    'count'     => $group->count(),
                    'open'      => $group->where('status', 'open')->count(),
                    'employees' => $group->pluck('employee_id')->unique()->count(),
                ])->values()->toArray();

            $total    = $leakage->count();
            $open     = $leakage->where('status', 'open')->count();

            $narrative = $total === 0
                ? "No payroll leakage detected for run {$run->ref_number}."
                : "Run {$run->ref_number} has {$total} potential leakage flag(s) with {$open} open. " . ($estimatedLoss > 0 ? "Estimated financial exposure: R" . number_format($estimatedLoss, 2) . "." : "");

            return [
                'narrative'       => $narrative,
                'by_type'         => $byType,
                'total'           => $total,
                'open'            => $open,
                'estimated_loss'  => round($estimatedLoss, 2),
                'items'           => $leakage->map(fn ($v) => [
                    'type'        => $v->anomaly_type,
                    'severity'    => $v->severity,
                    'status'      => $v->status,
                    'employee_id' => $v->employee_id,
                    'description' => $v->description,
                ])->values()->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error('generatePayrollLeakageReport error: ' . $e->getMessage());
            return ['narrative' => 'Unable to generate leakage report.', 'error' => true];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function runMeta(PayrollRun $run): array
    {
        return [
            'ref_number'   => $run->ref_number,
            'name'         => $run->name,
            'period_start' => $run->period_start->format('d M Y'),
            'period_end'   => $run->period_end->format('d M Y'),
            'status'       => $run->status,
            'total_gross'  => (float) $run->total_gross,
            'total_net'    => (float) $run->total_net,
            'employee_count' => (int) $run->employee_count,
        ];
    }
}
