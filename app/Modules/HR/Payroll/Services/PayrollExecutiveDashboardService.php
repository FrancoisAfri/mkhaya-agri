<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollApproval;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollExecutiveDashboardService
{
    private const COMPLIANCE_TYPES = [
        'overtime_law_violation', 'minimum_wage_violation', 'leave_compliance_issue',
        'contract_payroll_mismatch', 'tax_compliance_issue', 'overtime_rate_violation',
    ];

    private const LEAKAGE_TYPES = [
        'duplicate_allowance', 'allowance_leakage', 'benefit_after_termination',
        'suspicious_low_deductions', 'recurring_payroll_drift',
        'duplicate_payment', 'ghost_employee',
    ];

    // ── Master aggregator ─────────────────────────────────────────────────────

    public function getPayrollDashboardStats(): array
    {
        $now       = now();
        $thisYear  = $now->year;
        $thisMonth = $now->month;
        $lastMonth = $now->copy()->subMonth();

        try {
            // ── Costs ─────────────────────────────────────────────────────────
            $costThisMonth = (float) PayrollRun::where('status', 'released')
                ->whereYear('period_start', $thisYear)
                ->whereMonth('period_start', $thisMonth)
                ->sum('total_net');

            $costLastMonth = (float) PayrollRun::where('status', 'released')
                ->whereYear('period_start', $lastMonth->year)
                ->whereMonth('period_start', $lastMonth->month)
                ->sum('total_net');

            $momVariance    = $costThisMonth - $costLastMonth;
            $momVariancePct = $costLastMonth > 0
                ? round(($momVariance / $costLastMonth) * 100, 2)
                : null;

            // ── Risk ──────────────────────────────────────────────────────────
            $avgRisk = PayrollRun::whereNotNull('ai_risk_level')
                ->whereYear('period_start', $thisYear)
                ->whereMonth('period_start', $thisMonth)
                ->avg(DB::raw("CASE ai_risk_level WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'critical' THEN 4 ELSE 0 END"));

            $riskLabels   = [1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'critical'];
            $riskScore    = $riskLabels[(int) round((float) $avgRisk)] ?? 'low';

            // ── Leakage ───────────────────────────────────────────────────────
            $leakageEstimate = $this->estimateLeakage(1);

            // ── Overtime trend ────────────────────────────────────────────────
            $overtimeCurr = $this->overtimeSumForMonth($thisYear, $thisMonth);
            $overtimePrev = $this->overtimeSumForMonth($lastMonth->year, $lastMonth->month);
            $overtimeDiff = round($overtimeCurr - $overtimePrev, 2);

            // ── Leave liability ───────────────────────────────────────────────
            $leaveLiability = $this->leaveLiabilityEstimate();

            // ── High-risk entities ────────────────────────────────────────────
            $highRiskEmployees = PayrollAnomaly::whereIn('severity', ['high', 'critical'])
                ->where('status', 'open')
                ->distinct('employee_id')
                ->count('employee_id');

            $highRiskDepts = $this->highRiskDepartmentCount();

            // ── Compliance ────────────────────────────────────────────────────
            $complianceAlerts = PayrollAnomaly::whereIn('anomaly_type', self::COMPLIANCE_TYPES)
                ->where('status', 'open')
                ->count();

            // ── Pending approvals ─────────────────────────────────────────────
            $pendingApprovals = PayrollApproval::where('status', 'pending')->count();

            // ── AI summary ────────────────────────────────────────────────────
            $latestRun        = PayrollRun::latest('period_start')->first();
            $aiSummary        = $latestRun?->ai_anomaly_summary['summary'] ?? null;

            // ── Employee & run counts ─────────────────────────────────────────
            $employeesPaid = (int) DB::table('payslips')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payroll_runs.status', 'released')
                ->whereYear('payroll_runs.period_start', $thisYear)
                ->whereMonth('payroll_runs.period_start', $thisMonth)
                ->distinct('payslips.employee_id')
                ->count('payslips.employee_id');

            $openAnomalies = PayrollAnomaly::where('status', 'open')->count();
            $avgNetPay     = (float) DB::table('payslips')
                ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
                ->where('payroll_runs.status', 'released')
                ->whereYear('payroll_runs.period_start', $thisYear)
                ->whereMonth('payroll_runs.period_start', $thisMonth)
                ->avg('payslips.net_pay') ?? 0.0;

            return [
                'cost_this_month'    => $costThisMonth,
                'cost_last_month'    => $costLastMonth,
                'mom_variance'       => $momVariance,
                'mom_variance_pct'   => $momVariancePct,
                'risk_score'         => $riskScore,
                'leakage_estimate'   => $leakageEstimate,
                'overtime_curr'      => $overtimeCurr,
                'overtime_prev'      => $overtimePrev,
                'overtime_diff'      => $overtimeDiff,
                'leave_liability'    => $leaveLiability,
                'high_risk_employees'=> $highRiskEmployees,
                'high_risk_depts'    => $highRiskDepts,
                'compliance_alerts'  => $complianceAlerts,
                'pending_approvals'  => $pendingApprovals,
                'ai_summary'         => $aiSummary,
                'employees_paid'     => $employeesPaid,
                'open_anomalies'     => $openAnomalies,
                'avg_net_pay'        => round($avgNetPay, 2),
            ];
        } catch (\Throwable $e) {
            Log::error('getPayrollDashboardStats error: ' . $e->getMessage());
            return $this->emptyDashboardStats();
        }
    }

    // ── Section dashboards ────────────────────────────────────────────────────

    public function getPayrollRiskDashboard(): array
    {
        try {
            $openAnomalies = DB::table('payroll_anomalies')
                ->where('status', 'open')
                ->select('severity', DB::raw('COUNT(*) as cnt'))
                ->groupBy('severity')
                ->get()->keyBy('severity');

            $total    = (int) DB::table('payroll_anomalies')->where('status', 'open')->count();
            $critical = (int) ($openAnomalies['critical']->cnt ?? 0);
            $high     = (int) ($openAnomalies['high']->cnt ?? 0);

            $riskScore = match(true) {
                $critical > 0 => 'critical',
                $high > 3     => 'high',
                $high > 0     => 'medium',
                default       => 'low',
            };

            // High-risk employees (top 10)
            $highRiskEmployees = DB::table('payroll_anomalies as pa')
                ->join('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                ->where('pa.status', 'open')
                ->whereIn('pa.severity', ['high', 'critical'])
                ->select(
                    'pa.employee_id',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) as name"),
                    DB::raw('COUNT(*) as anomaly_count'),
                    DB::raw("MAX(CASE pa.severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 ELSE 2 END) as max_risk"),
                )
                ->groupBy('pa.employee_id', 'hp.first_name', 'hp.surname')
                ->orderByDesc('max_risk')
                ->orderByDesc('anomaly_count')
                ->limit(10)
                ->get()->toArray();

            // High-risk departments
            $highRiskDepts = $this->getHighRiskDepartments();

            // Risk trend (open anomalies per month for last 6 months)
            $trend = DB::table('payroll_anomalies')
                ->where('status', 'open')
                ->whereIn('severity', ['high', 'critical'])
                ->where('detected_at', '>=', now()->subMonths(6)->startOfMonth())
                ->select(
                    DB::raw("TO_CHAR(detected_at, 'YYYY-MM') as month"),
                    DB::raw('COUNT(*) as cnt'),
                )
                ->groupBy(DB::raw("TO_CHAR(detected_at, 'YYYY-MM')"))
                ->orderBy('month')
                ->get()->toArray();

            return [
                'risk_score'         => $riskScore,
                'total_open'         => $total,
                'breakdown'          => ['critical' => $critical, 'high' => $high, 'medium' => (int) ($openAnomalies['medium']->cnt ?? 0), 'low' => (int) ($openAnomalies['low']->cnt ?? 0)],
                'high_risk_employees'=> $highRiskEmployees,
                'high_risk_depts'    => $highRiskDepts,
                'trend'              => $trend,
            ];
        } catch (\Throwable $e) {
            Log::error('getPayrollRiskDashboard error: ' . $e->getMessage());
            return ['risk_score' => 'low', 'total_open' => 0, 'breakdown' => [], 'high_risk_employees' => [], 'high_risk_depts' => [], 'trend' => []];
        }
    }

    public function getPayrollLeakageDashboard(): array
    {
        try {
            $leakage = DB::table('payroll_anomalies')
                ->whereIn('anomaly_type', self::LEAKAGE_TYPES)
                ->whereIn('status', ['open', 'investigating'])
                ->select('anomaly_type', 'severity', 'metadata', 'employee_id')
                ->get();

            $estimatedLoss = $leakage->sum(function ($row) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                return (float) ($meta['estimated_loss'] ?? $meta['overpayment_amount'] ?? 0);
            });

            $byType = $leakage->groupBy('anomaly_type')
                ->map(fn ($g, $t) => ['type' => $t, 'count' => $g->count(), 'employees' => $g->pluck('employee_id')->unique()->count()])
                ->values()->toArray();

            $byDept = DB::table('payroll_anomalies as pa')
                ->join('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                ->whereIn('pa.anomaly_type', self::LEAKAGE_TYPES)
                ->whereIn('pa.status', ['open', 'investigating'])
                ->whereNotNull('hp.division_level_1')
                ->select('hp.division_level_1 as department', DB::raw('COUNT(*) as cnt'))
                ->groupBy('hp.division_level_1')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get()->toArray();

            return [
                'total_flags'     => $leakage->count(),
                'estimated_loss'  => round($estimatedLoss, 2),
                'by_type'         => $byType,
                'by_dept'         => $byDept,
            ];
        } catch (\Throwable $e) {
            Log::error('getPayrollLeakageDashboard error: ' . $e->getMessage());
            return ['total_flags' => 0, 'estimated_loss' => 0, 'by_type' => [], 'by_dept' => []];
        }
    }

    public function getPayrollCostDashboard(): array
    {
        try {
            $monthly = DB::table('payroll_runs')
                ->where('status', 'released')
                ->where('period_start', '>=', now()->subMonths(12)->startOfMonth())
                ->select(
                    DB::raw("TO_CHAR(period_start, 'YYYY-MM') as month"),
                    DB::raw('SUM(total_net) as total_net'),
                    DB::raw('SUM(total_gross) as total_gross'),
                    DB::raw('COUNT(*) as run_count'),
                )
                ->groupBy(DB::raw("TO_CHAR(period_start, 'YYYY-MM')"))
                ->orderBy('month')
                ->get()
                ->map(fn ($r) => [
                    'month'       => $r->month,
                    'label'       => \Carbon\Carbon::createFromFormat('Y-m', $r->month)->format('M Y'),
                    'total_net'   => round((float) $r->total_net, 2),
                    'total_gross' => round((float) $r->total_gross, 2),
                ])->toArray();

            $ytdTotal = (float) DB::table('payroll_runs')
                ->where('status', 'released')
                ->whereYear('period_start', now()->year)
                ->sum('total_net');

            return [
                'monthly_trend' => $monthly,
                'ytd_total'     => round($ytdTotal, 2),
            ];
        } catch (\Throwable $e) {
            Log::error('getPayrollCostDashboard error: ' . $e->getMessage());
            return ['monthly_trend' => [], 'ytd_total' => 0];
        }
    }

    public function getPayrollVarianceDashboard(): array
    {
        try {
            $curr = PayrollRun::where('status', 'released')->latest('period_start')->first();
            $prev = $curr
                ? PayrollRun::where('status', 'released')->where('period_start', '<', $curr->period_start)->latest('period_start')->first()
                : null;

            if (!$curr || !$prev) {
                return ['has_data' => false, 'by_dept' => []];
            }

            $byDept = DB::table('payslips as ps')
                ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                ->whereIn('ps.payroll_run_id', [$curr->id, $prev->id])
                ->whereNotNull('hp.division_level_1')
                ->select(
                    'hp.division_level_1 as department',
                    'ps.payroll_run_id',
                    DB::raw('SUM(ps.net_pay) as total_net'),
                )
                ->groupBy('hp.division_level_1', 'ps.payroll_run_id')
                ->get()
                ->groupBy('department')
                ->map(function ($rows, $dept) use ($curr, $prev) {
                    $currTotal = (float) ($rows->firstWhere('payroll_run_id', $curr->id)?->total_net ?? 0);
                    $prevTotal = (float) ($rows->firstWhere('payroll_run_id', $prev->id)?->total_net ?? 0);
                    $diff      = $currTotal - $prevTotal;
                    return [
                        'department' => $dept,
                        'curr'       => round($currTotal, 2),
                        'prev'       => round($prevTotal, 2),
                        'diff'       => round($diff, 2),
                        'pct'        => $prevTotal > 0 ? round($diff / $prevTotal * 100, 1) : null,
                    ];
                })->sortByDesc(fn ($r) => abs($r['diff']))->values()->toArray();

            return [
                'has_data' => true,
                'curr_run' => ['ref_number' => $curr->ref_number, 'period_start' => $curr->period_start->toDateString()],
                'prev_run' => ['ref_number' => $prev->ref_number, 'period_start' => $prev->period_start->toDateString()],
                'net_diff' => round((float) $curr->total_net - (float) $prev->total_net, 2),
                'by_dept'  => array_slice($byDept, 0, 8),
            ];
        } catch (\Throwable $e) {
            Log::error('getPayrollVarianceDashboard error: ' . $e->getMessage());
            return ['has_data' => false, 'by_dept' => []];
        }
    }

    public function getComplianceDashboard(): array
    {
        try {
            $violations = DB::table('payroll_anomalies')
                ->whereIn('anomaly_type', self::COMPLIANCE_TYPES)
                ->where('status', 'open')
                ->select('anomaly_type', 'severity', DB::raw('COUNT(*) as cnt'))
                ->groupBy('anomaly_type', 'severity')
                ->get();

            $total    = $violations->sum('cnt');
            $critical = $violations->where('severity', 'critical')->sum('cnt');

            $byType = $violations->groupBy('anomaly_type')
                ->map(fn ($g, $t) => ['type' => $t, 'count' => $g->sum('cnt'), 'critical' => $g->where('severity', 'critical')->sum('cnt')])
                ->values()->toArray();

            $recentlyResolved = DB::table('payroll_anomalies')
                ->whereIn('anomaly_type', self::COMPLIANCE_TYPES)
                ->where('status', 'resolved')
                ->where('resolved_at', '>=', now()->subDays(30))
                ->count();

            return [
                'total_open'        => (int) $total,
                'critical'          => (int) $critical,
                'by_type'           => $byType,
                'recently_resolved' => (int) $recentlyResolved,
            ];
        } catch (\Throwable $e) {
            Log::error('getComplianceDashboard error: ' . $e->getMessage());
            return ['total_open' => 0, 'critical' => 0, 'by_type' => [], 'recently_resolved' => 0];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function overtimeSumForMonth(int $year, int $month): float
    {
        return (float) DB::table('payroll_earnings_lines as pel')
            ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', 'released')
            ->whereYear('pr.period_start', $year)
            ->whereMonth('pr.period_start', $month)
            ->where('pel.line_type', 'overtime')
            ->sum('pel.amount');
    }

    private function leaveLiabilityEstimate(): float
    {
        try {
            $avgDailyRate = (float) DB::table('payslips as ps')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('pr.status', 'released')
                ->whereYear('pr.period_start', now()->year)
                ->selectRaw('AVG(basic_salary / 22.0) as avg_daily')
                ->value('avg_daily');

            $pendingDays = (int) DB::table('leave_requests')
                ->where('status', 'approved')
                ->whereYear('start_date', now()->year)
                ->selectRaw("SUM(CAST(end_date AS date) - CAST(start_date AS date) + 1) as total_days")
                ->value('total_days');

            return round($avgDailyRate * $pendingDays, 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function estimateLeakage(int $months): float
    {
        try {
            $since  = now()->subMonths($months)->startOfMonth();
            $runIds = DB::table('payroll_runs')
                ->whereIn('status', ['released', 'review'])
                ->where('period_start', '>=', $since)
                ->pluck('id');

            $anomalies = DB::table('payroll_anomalies')
                ->whereIn('run_id', $runIds)
                ->whereIn('anomaly_type', self::LEAKAGE_TYPES)
                ->whereIn('status', ['open', 'investigating'])
                ->select('metadata')
                ->get();

            return $anomalies->sum(function ($row) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                return (float) ($meta['estimated_loss'] ?? $meta['overpayment_amount'] ?? 0);
            });
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function highRiskDepartmentCount(): int
    {
        try {
            return DB::table('payroll_anomalies as pa')
                ->join('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                ->where('pa.status', 'open')
                ->whereIn('pa.severity', ['high', 'critical'])
                ->whereNotNull('hp.division_level_1')
                ->distinct('hp.division_level_1')
                ->count('hp.division_level_1');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getHighRiskDepartments(): array
    {
        try {
            return DB::table('payroll_anomalies as pa')
                ->join('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                ->where('pa.status', 'open')
                ->whereIn('pa.severity', ['high', 'critical'])
                ->whereNotNull('hp.division_level_1')
                ->select('hp.division_level_1 as department', DB::raw('COUNT(DISTINCT pa.employee_id) as employee_count'), DB::raw('COUNT(*) as anomaly_count'))
                ->groupBy('hp.division_level_1')
                ->orderByDesc('anomaly_count')
                ->limit(5)
                ->get()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function emptyDashboardStats(): array
    {
        return [
            'cost_this_month' => 0, 'cost_last_month' => 0, 'mom_variance' => 0,
            'mom_variance_pct' => null, 'risk_score' => 'low', 'leakage_estimate' => 0,
            'overtime_curr' => 0, 'overtime_prev' => 0, 'overtime_diff' => 0,
            'leave_liability' => 0, 'high_risk_employees' => 0, 'high_risk_depts' => 0,
            'compliance_alerts' => 0, 'pending_approvals' => 0, 'ai_summary' => null,
            'employees_paid' => 0, 'open_anomalies' => 0, 'avg_net_pay' => 0,
        ];
    }
}
