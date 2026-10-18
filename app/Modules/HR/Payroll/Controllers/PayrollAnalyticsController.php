<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollAnalyticsController extends Controller
{
    private ?PayrollRun $run  = null;
    private string $dateFrom  = '';
    private string $dateTo    = '';
    private string $period    = 'year';
    private ?string $dept     = null;
    private ?string $runStatus = null;

    public function index(Request $request): Response
    {
        $this->period    = $request->input('period', 'year');
        $this->dept      = $request->input('department');
        $this->runStatus = $request->input('run_status');

        [$this->dateFrom, $this->dateTo] = $this->dateRange();

        $this->run = PayrollRun::where('status', PayrollRunStatus::Released->value)
            ->latest('period_start')->first();

        $departments = DB::table('company_units')->where('type', 'department')->orderBy('name')->pluck('name');

        return Inertia::render('Modules/HR/Payroll/Analytics/Index', [
            'period'       => $this->period,
            'dateFrom'     => $this->dateFrom,
            'dateTo'       => $this->dateTo,
            'filters'      => ['department' => $this->dept, 'run_status' => $this->runStatus],
            'filterOptions' => ['departments' => $departments, 'statuses' => ['released', 'review', 'processing', 'draft']],
            'kpis'         => $this->kpis(),
            'monthlyCost'  => $this->monthlyCostTrend(),
            'byDepartment' => $this->byDepartment(),
            'runBreakdown' => $this->runBreakdown(),
            'anomalyDist'  => $this->anomalyDistribution(),
            'topEarners'   => $this->topEarners(),
            'riskTrend'    => $this->riskTrend(),
            'grossVsNet'   => $this->grossVsNet(),
            'taxSummary'   => $this->taxSummary(),
            'overtimeTrend'=> $this->overtimeTrend(),
        ]);
    }

    // ── KPIs ──────────────────────────────────────────────────────────────────

    private function kpis(): array
    {
        $curr = DB::table('payroll_runs')
            ->where('status', PayrollRunStatus::Released->value)
            ->whereBetween('period_start', [$this->dateFrom, $this->dateTo])
            ->selectRaw('COUNT(*) as runs, SUM(total_net) as total_net, SUM(total_gross) as total_gross, SUM(total_deductions) as total_deductions, SUM(employee_count) as headcount')
            ->first();

        $prevFrom = date('Y-m-d', strtotime($this->dateFrom . ' -1 year'));
        $prevTo   = date('Y-m-d', strtotime($this->dateTo   . ' -1 year'));

        $prev = DB::table('payroll_runs')
            ->where('status', PayrollRunStatus::Released->value)
            ->whereBetween('period_start', [$prevFrom, $prevTo])
            ->value(DB::raw('SUM(total_net)'));

        $momVariance = 0;
        if ($prev > 0) {
            $momVariance = round((((float) ($curr->total_net ?? 0) - (float) $prev) / (float) $prev) * 100, 2);
        }

        $openAnomalies    = DB::table('payroll_anomalies')->where('status', 'open')->count();
        $criticalAnomalies = DB::table('payroll_anomalies')->where('status', 'open')->where('severity', 'critical')->count();
        $pendingApprovals = DB::table('payroll_approvals')->whereIn('status', ['pending', 'escalated'])->count();
        $complianceAlerts = DB::table('payroll_anomalies')->where('status', 'open')->whereIn('anomaly_type', ['tax_threshold_breach', 'uif_cap_exceeded', 'nwm_violation'])->count();

        $latestRun = DB::table('payroll_runs')->where('status', PayrollRunStatus::Released->value)->orderBy('period_start', 'desc')->first();
        $avgNetPay = $latestRun
            ? (float) DB::table('payslips')->where('payroll_run_id', $latestRun->id)->avg('net_pay')
            : 0;

        return [
            'total_cost'         => round((float) ($curr->total_net ?? 0), 2),
            'total_gross'        => round((float) ($curr->total_gross ?? 0), 2),
            'total_deductions'   => round((float) ($curr->total_deductions ?? 0), 2),
            'total_runs'         => (int) ($curr->runs ?? 0),
            'total_headcount'    => (int) ($curr->headcount ?? 0),
            'avg_net_pay'        => round($avgNetPay, 2),
            'mom_variance_pct'   => $momVariance,
            'open_anomalies'     => $openAnomalies,
            'critical_anomalies' => $criticalAnomalies,
            'pending_approvals'  => $pendingApprovals,
            'compliance_alerts'  => $complianceAlerts,
        ];
    }

    // ── 12-month cost trend ───────────────────────────────────────────────────

    private function monthlyCostTrend(): array
    {
        return DB::table('payroll_runs')
            ->where('status', PayrollRunStatus::Released->value)
            ->where('period_start', '>=', now()->subMonths(12)->startOfMonth()->toDateString())
            ->selectRaw("TO_CHAR(period_start,'YYYY-MM') as month, SUM(total_gross) as gross, SUM(total_net) as net, SUM(total_deductions) as deductions, SUM(employee_count) as headcount")
            ->groupByRaw("TO_CHAR(period_start,'YYYY-MM')")
            ->orderByRaw("TO_CHAR(period_start,'YYYY-MM')")
            ->get()->map(fn($r) => [
                'month'      => $r->month,
                'gross'      => round((float) $r->gross, 2),
                'net'        => round((float) $r->net, 2),
                'deductions' => round((float) $r->deductions, 2),
                'headcount'  => (int) $r->headcount,
            ])->toArray();
    }

    // ── Cost by department ────────────────────────────────────────────────────

    private function byDepartment(): array
    {
        $latestRun = DB::table('payroll_runs')->where('status', 'released')->orderBy('period_start', 'desc')->value('id');
        if (! $latestRun) return [];

        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->where('ps.payroll_run_id', $latestRun)
            ->when($this->dept, fn($q) => $q->where('cu.name', $this->dept))
            ->selectRaw("COALESCE(cu.name, hp.division_level_1::text, 'Unassigned') as department, COUNT(*) as headcount, ROUND(SUM(ps.gross_pay),2) as total_gross, ROUND(SUM(ps.net_pay),2) as total_net, ROUND(AVG(ps.net_pay),2) as avg_net")
            ->groupByRaw("COALESCE(cu.name, hp.division_level_1::text, 'Unassigned')")
            ->orderByRaw("SUM(ps.net_pay) DESC")
            ->limit(15)->get()->map(fn($r) => (array) $r)->toArray();
    }

    // ── Run breakdown by status ───────────────────────────────────────────────

    private function runBreakdown(): array
    {
        return DB::table('payroll_runs')
            ->whereBetween('period_start', [$this->dateFrom, $this->dateTo])
            ->when($this->runStatus, fn($q) => $q->where('status', $this->runStatus))
            ->selectRaw('status, COUNT(*) as count, SUM(total_net) as total_net')
            ->groupBy('status')
            ->get()->map(fn($r) => [
                'status'    => $r->status,
                'count'     => (int) $r->count,
                'total_net' => round((float) $r->total_net, 2),
            ])->toArray();
    }

    // ── Anomaly distribution by type ─────────────────────────────────────────

    private function anomalyDistribution(): array
    {
        return DB::table('payroll_anomalies')
            ->selectRaw('anomaly_type, severity, COUNT(*) as count')
            ->groupBy('anomaly_type', 'severity')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(20)->get()->map(fn($r) => [
                'anomaly_type' => $r->anomaly_type,
                'severity'     => $r->severity,
                'count'        => (int) $r->count,
            ])->toArray();
    }

    // ── Top earners ───────────────────────────────────────────────────────────

    private function topEarners(): array
    {
        $latestRun = DB::table('payroll_runs')->where('status', 'released')->orderBy('period_start', 'desc')->value('id');
        if (! $latestRun) return [];

        return DB::table('payslips as ps')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->where('ps.payroll_run_id', $latestRun)
            ->selectRaw("TRIM(CONCAT(hp.first_name,' ',COALESCE(hp.surname,''))) as employee, COALESCE(cu.name, hp.division_level_1::text,'Unassigned') as department, ROUND(ps.gross_pay,2) as gross_pay, ROUND(ps.net_pay,2) as net_pay, ROUND(ps.paye,2) as paye")
            ->orderBy('ps.net_pay', 'desc')
            ->limit(10)->get()->map(fn($r) => (array) $r)->toArray();
    }

    // ── AI Risk score trend (6 months) ────────────────────────────────────────

    private function riskTrend(): array
    {
        return DB::table('payroll_runs as pr')
            ->where('pr.status', PayrollRunStatus::Released->value)
            ->where('pr.period_start', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("TO_CHAR(pr.period_start,'YYYY-MM') as month, pr.ai_risk_level, COUNT(DISTINCT pa.id) as anomaly_count")
            ->leftJoin('payroll_anomalies as pa', 'pa.run_id', '=', 'pr.id')
            ->groupByRaw("TO_CHAR(pr.period_start,'YYYY-MM'), pr.ai_risk_level")
            ->orderByRaw("TO_CHAR(pr.period_start,'YYYY-MM')")
            ->get()->map(fn($r) => [
                'month'         => $r->month,
                'risk_level'    => $r->ai_risk_level ?? 'unknown',
                'anomaly_count' => (int) $r->anomaly_count,
            ])->toArray();
    }

    // ── Gross vs Net by run ───────────────────────────────────────────────────

    private function grossVsNet(): array
    {
        return DB::table('payroll_runs')
            ->where('status', PayrollRunStatus::Released->value)
            ->where('period_start', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("ref_number as run, TO_CHAR(period_start,'Mon YYYY') as label, ROUND(total_gross,2) as gross, ROUND(total_net,2) as net, ROUND(total_deductions,2) as deductions, employee_count")
            ->orderBy('period_start')
            ->get()->map(fn($r) => (array) $r)->toArray();
    }

    // ── Tax summary ───────────────────────────────────────────────────────────

    private function taxSummary(): array
    {
        $latestRun = DB::table('payroll_runs')->where('status', 'released')->orderBy('period_start', 'desc')->value('id');
        if (! $latestRun) return ['paye_total' => 0, 'uif_total' => 0, 'pension_total' => 0, 'by_bracket' => []];

        $totals = DB::table('payslips')->where('payroll_run_id', $latestRun)
            ->selectRaw('ROUND(SUM(paye),2) as paye_total, ROUND(SUM(uif),2) as uif_total, ROUND(SUM(pension),2) as pension_total')
            ->first();

        $byBracket = DB::table('payslips')
            ->where('payroll_run_id', $latestRun)
            ->selectRaw("CASE WHEN gross_pay < 7979 THEN 'Below threshold' WHEN gross_pay < 15000 THEN 'R7,979–R15,000' WHEN gross_pay < 30000 THEN 'R15,000–R30,000' WHEN gross_pay < 50000 THEN 'R30,000–R50,000' ELSE 'R50,000+' END as bracket, COUNT(*) as count, ROUND(SUM(paye),2) as paye_total")
            ->groupByRaw("CASE WHEN gross_pay < 7979 THEN 'Below threshold' WHEN gross_pay < 15000 THEN 'R7,979–R15,000' WHEN gross_pay < 30000 THEN 'R15,000–R30,000' WHEN gross_pay < 50000 THEN 'R30,000–R50,000' ELSE 'R50,000+' END")
            ->orderByRaw('MIN(gross_pay)')
            ->get()->map(fn($r) => (array) $r)->toArray();

        return [
            'paye_total'    => (float) ($totals->paye_total ?? 0),
            'uif_total'     => (float) ($totals->uif_total ?? 0),
            'pension_total' => (float) ($totals->pension_total ?? 0),
            'by_bracket'    => $byBracket,
        ];
    }

    // ── Overtime trend ────────────────────────────────────────────────────────

    private function overtimeTrend(): array
    {
        return DB::table('payroll_earnings_lines as el')
            ->join('payslips as ps', 'ps.id', '=', 'el.payslip_id')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->where('pr.status', PayrollRunStatus::Released->value)
            ->where('el.line_type', 'overtime')
            ->where('pr.period_start', '>=', now()->subMonths(6)->startOfMonth()->toDateString())
            ->selectRaw("TO_CHAR(pr.period_start,'YYYY-MM') as month, COUNT(DISTINCT ps.employee_id) as employees, ROUND(SUM(el.quantity),2) as total_hours, ROUND(SUM(el.amount),2) as total_cost")
            ->groupByRaw("TO_CHAR(pr.period_start,'YYYY-MM')")
            ->orderByRaw("TO_CHAR(pr.period_start,'YYYY-MM')")
            ->get()->map(fn($r) => [
                'month'       => $r->month,
                'employees'   => (int) $r->employees,
                'total_hours' => (float) $r->total_hours,
                'total_cost'  => round((float) $r->total_cost, 2),
            ])->toArray();
    }

    // ── Date range helper ─────────────────────────────────────────────────────

    private function dateRange(): array
    {
        return match ($this->period) {
            'month'    => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'quarter'  => [now()->startOfQuarter()->toDateString(), now()->endOfQuarter()->toDateString()],
            'halfyear' => [now()->subMonths(6)->startOfMonth()->toDateString(), now()->toDateString()],
            'lastyear' => [now()->subYear()->startOfYear()->toDateString(), now()->subYear()->endOfYear()->toDateString()],
            default    => [now()->startOfYear()->toDateString(), now()->toDateString()], // year
        };
    }
}
