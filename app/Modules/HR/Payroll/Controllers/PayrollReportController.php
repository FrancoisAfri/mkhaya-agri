<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Jobs\GeneratePayrollReportJob;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollReportController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $runId = request()->integer('run_id') ?: null;
        $run   = $runId ? PayrollRun::find($runId) : PayrollRun::where('status', PayrollRunStatus::Released->value)->latest('period_start')->first();

        [$ne, $de] = $this->nameAndDeptExprs();

        $payslips = $this->queryPayslips($run, $ne, $de);
        $grossBreakdown = $this->queryGrossBreakdown($run, $ne, $de);
        $deductions     = $this->queryDeductions($run, $ne, $de);
        $netPay         = $this->queryNetPay($run, $ne, $de);
        $overtime       = $this->queryOvertime($run, $ne, $de);
        $headcount      = $this->queryHeadcount($run, $de);
        $variance       = $this->queryVariance($ne, $de);
        $tax            = $this->queryTax($run, $ne, $de);
        $anomalies      = $this->queryAnomalies($run, $ne, $de);
        $corrections    = $this->queryCorrections($run, $ne);
        $leakage        = $this->queryLeakage($run, $ne, $de);

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name', 'period_start', 'period_end', 'status', 'total_net', 'employee_count')
            ->latest('period_start')->limit(24)->get();

        $departments = DB::table('company_units')->where('type', 'department')->orderBy('name')->pluck('name');
        $employees   = DB::table('hr_people')->select('user_id as id', DB::raw("TRIM(CONCAT(first_name,' ',COALESCE(surname,''))) as name"))->orderBy('first_name')->get();

        $stats = [
            'total_runs'       => PayrollRun::count(),
            'total_released'   => PayrollRun::where('status', PayrollRunStatus::Released->value)->count(),
            'avg_monthly_cost' => round((float) PayrollRun::where('status', PayrollRunStatus::Released->value)->avg('total_net'), 2),
            'open_anomalies'   => DB::table('payroll_anomalies')->where('status', 'open')->count(),
        ];

        $monthlyCosts = DB::table('payroll_runs')
            ->where('status', PayrollRunStatus::Released->value)
            ->selectRaw("TO_CHAR(period_start, 'YYYY-MM') as month, SUM(total_net) as total")
            ->groupByRaw("TO_CHAR(period_start, 'YYYY-MM')")
            ->orderByRaw("TO_CHAR(period_start, 'YYYY-MM') DESC")
            ->limit(12)->get()
            ->map(fn($r) => ['month' => $r->month, 'total' => round((float) $r->total, 2)]);

        return Inertia::render('Modules/HR/Payroll/Reports/Index', [
            'currentRun'     => $run ? $run->only(['id', 'uuid', 'ref_number', 'name', 'period_start', 'period_end', 'total_gross', 'total_net', 'employee_count', 'status']) : null,
            'runs'           => $runs,
            'departments'    => $departments,
            'employees'      => $employees,
            'stats'          => $stats,
            'monthlyCosts'   => $monthlyCosts,
            'payslips'       => $payslips,
            'grossBreakdown' => $grossBreakdown,
            'deductions'     => $deductions,
            'netPay'         => $netPay,
            'overtime'       => $overtime,
            'headcount'      => $headcount,
            'variance'       => $variance,
            'tax'            => $tax,
            'anomalies'      => $anomalies,
            'corrections'    => $corrections,
            'leakage'        => $leakage,
            'reportKeys'     => $this->reportKeys(),
        ]);
    }

    // ── Generate (row count for progress indicator) ───────────────────────────

    public function generate(string $key): JsonResponse
    {
        $run = request()->integer('run_id')
            ? PayrollRun::find(request()->integer('run_id'))
            : PayrollRun::where('status', PayrollRunStatus::Released->value)->latest('period_start')->first();

        $job = new GeneratePayrollReportJob($key, null, $run?->id);
        [, $rows] = $job->buildData();

        return response()->json(['key' => $key, 'count' => count($rows)]);
    }

    // ── Queue (async email) ───────────────────────────────────────────────────

    public function queue(Request $request, string $key): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $runId = $request->integer('run_id') ?: null;

        GeneratePayrollReportJob::dispatch($key, $request->email, $runId);

        return response()->json(['queued' => true, 'message' => "Report queued. You'll receive it at {$request->email} shortly."]);
    }

    // ── Export (inline download) ──────────────────────────────────────────────

    public function export(string $key, string $format): mixed
    {
        $runId = request()->integer('run_id') ?: null;
        $job   = new GeneratePayrollReportJob($key, null, $runId);
        [$headers, $rows] = $job->buildData();

        $slug = 'payroll-' . str_replace('_', '-', $key) . '-' . now()->format('Y-m-d');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($headers, $rows) {
                $fp = fopen('php://output', 'w');
                fputcsv($fp, $headers);
                foreach ($rows as $row) {
                    fputcsv($fp, array_values((array) $row));
                }
                fclose($fp);
            }, "{$slug}.csv", ['Content-Type' => 'text/csv']);
        }

        // PDF
        $title    = ucwords(str_replace('_', ' ', $key)) . ' Payroll Report';
        $date     = now()->format('d M Y');
        $thHtml   = implode('', array_map(fn($h) => '<th>' . e($h) . '</th>', $headers));
        $rowsHtml = '';
        foreach ($rows as $row) {
            $row = (array) $row;
            $rowsHtml .= '<tr>' . implode('', array_map(fn($v) => '<td>' . e((string) $v) . '</td>', array_values($row))) . '</tr>';
        }

        $html = "<html><head><style>
            body{font-family:Arial,sans-serif;font-size:9px;color:#222;}
            h2{margin:0 0 4px;color:#1A237E;font-size:13px;}p{margin:0 0 8px;color:#666;}
            table{width:100%;border-collapse:collapse;}
            th,td{border:1px solid #ddd;padding:4px 6px;text-align:left;}
            th{background:#E8EAF6;font-weight:bold;color:#1A237E;}
            tr:nth-child(even){background:#f9f9f9;}
        </style></head><body>
        <h2>{$title}</h2><p>Generated {$date}</p>
        <table><thead><tr>{$thHtml}</tr></thead><tbody>{$rowsHtml}</tbody></table>
        </body></html>";

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->download("{$slug}.pdf");
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    private function nameAndDeptExprs(): array
    {
        return [
            "TRIM(CONCAT(hp.first_name, ' ', COALESCE(hp.surname, '')))",
            "COALESCE(cu.name, hp.division_level_1::text, 'Unassigned')",
        ];
    }

    private function queryPayslips(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, TO_CHAR(pr.period_start,'Mon YYYY') as period, ROUND(ps.basic_salary,2) as basic_salary, ROUND(ps.gross_pay,2) as gross_pay, ROUND(ps.total_deductions,2) as deductions, ROUND(ps.paye,2) as paye, ROUND(ps.uif,2) as uif, ROUND(ps.pension,2) as pension, ROUND(ps.net_pay,2) as net_pay, ps.status")
            ->orderBy('pr.period_start', 'desc')->orderByRaw($ne)
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryGrossBreakdown(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payroll_earnings_lines as el')
            ->join('payslips as ps', 'ps.id', '=', 'el.payslip_id')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->whereNotIn('el.line_type', ['paye', 'uif', 'pension', 'other_deduction'])
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, el.line_type, el.description, ROUND(el.quantity,2) as qty, ROUND(el.amount,2) as amount")
            ->orderBy('pr.period_start', 'desc')->orderByRaw($ne)
            ->limit(1000)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryDeductions(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, ROUND(ps.paye,2) as paye, ROUND(ps.uif,2) as uif, ROUND(ps.pension,2) as pension, ROUND(ps.total_deductions,2) as total_deductions")
            ->orderBy('pr.period_start', 'desc')->orderByRaw($ne)
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryNetPay(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, TO_CHAR(pr.period_start,'dd Mon YYYY') as period_start, TO_CHAR(pr.period_end,'dd Mon YYYY') as period_end, ROUND(ps.gross_pay,2) as gross_pay, ROUND(ps.total_deductions,2) as deductions, ROUND(ps.net_pay,2) as net_pay")
            ->orderBy('ps.net_pay', 'desc')
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryOvertime(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payroll_earnings_lines as el')
            ->join('payslips as ps', 'ps.id', '=', 'el.payslip_id')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->where('el.line_type', 'overtime')
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, el.description, ROUND(el.quantity,2) as hours, ROUND(el.amount,2) as amount")
            ->orderBy('el.amount', 'desc')
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryHeadcount(?PayrollRun $run, string $de): array
    {
        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("pr.ref_number as run, TO_CHAR(pr.period_start,'Mon YYYY') as period, {$de} as department, COUNT(*) as headcount, ROUND(SUM(ps.net_pay),2) as total_net, ROUND(AVG(ps.net_pay),2) as avg_net")
            ->groupByRaw("pr.ref_number, pr.period_start, {$de}")
            ->orderBy('pr.period_start', 'desc')->orderByRaw("SUM(ps.net_pay) DESC")
            ->limit(200)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryVariance(string $ne, string $de): array
    {
        $runs = DB::table('payroll_runs')->where('status', 'released')->orderBy('period_start', 'desc')->limit(2)->get();
        if ($runs->count() < 2) return [];

        [$curr, $prev] = [$runs[0], $runs[1]];

        return DB::table('payslips as c')
            ->join('payslips as p', fn($j) => $j->on('p.employee_id', '=', 'c.employee_id')->where('p.payroll_run_id', $prev->id))
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'c.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'c.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->where('c.payroll_run_id', $curr->id)
            ->selectRaw("{$ne} as employee, {$de} as department, ROUND(p.net_pay,2) as previous_net, ROUND(c.net_pay,2) as current_net, ROUND(c.net_pay-p.net_pay,2) as variance_r, CASE WHEN p.net_pay>0 THEN ROUND(((c.net_pay-p.net_pay)/p.net_pay)*100,2) ELSE 0 END as variance_pct")
            ->orderByRaw("ABS(c.net_pay-p.net_pay) DESC")
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryTax(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payslips as ps')
            ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->when($run, fn($q) => $q->where('ps.payroll_run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, pr.ref_number as run, ROUND(ps.gross_pay,2) as gross_pay, ROUND(ps.gross_pay-ps.pension,2) as taxable_income, ROUND(ps.paye,2) as paye, ROUND(ps.uif,2) as uif, ROUND(ps.pension,2) as pension, ROUND(ps.paye+ps.uif+ps.pension,2) as total_tax")
            ->orderBy('ps.paye', 'desc')
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryAnomalies(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payroll_anomalies as pa')
            ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pa.run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'pa.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->when($run, fn($q) => $q->where('pa.run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, COALESCE(pr.ref_number,'N/A') as run, pa.anomaly_type, pa.severity, pa.description, pa.status, TO_CHAR(pa.detected_at,'dd Mon YYYY') as detected_at")
            ->orderBy('pa.detected_at', 'desc')
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryCorrections(?PayrollRun $run, string $ne): array
    {
        return DB::table('payroll_corrections as pc')
            ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pc.run_id')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pc.employee_id')
            ->when($run, fn($q) => $q->where('pc.run_id', $run->id))
            ->selectRaw("{$ne} as employee, COALESCE(pr.ref_number,'N/A') as run, pc.correction_type, pc.field_affected, ROUND(pc.original_value,2) as original_value, ROUND(pc.corrected_value,2) as corrected_value, pc.status, pc.applied_at")
            ->orderBy('pc.created_at', 'desc')
            ->limit(500)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function queryLeakage(?PayrollRun $run, string $ne, string $de): array
    {
        return DB::table('payroll_anomalies as pa')
            ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pa.run_id')
            ->leftJoin('payslips as ps', fn($j) => $j->on('ps.employee_id', '=', 'pa.employee_id')->on('ps.payroll_run_id', '=', 'pa.run_id'))
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'pa.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->whereIn('pa.anomaly_type', ['ghost_employee', 'duplicate_payment', 'terminated_employee_paid', 'inactive_employee_paid', 'overpayment'])
            ->when($run, fn($q) => $q->where('pa.run_id', $run->id))
            ->selectRaw("{$ne} as employee, {$de} as department, COALESCE(pr.ref_number,'N/A') as run, pa.anomaly_type, ROUND(COALESCE(ps.net_pay,0),2) as net_pay_at_risk, pa.severity, pa.status, pa.description")
            ->orderBy('pa.detected_at', 'desc')
            ->limit(300)->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function reportKeys(): array
    {
        return [
            'payslips'        => 'Payslip Summary',
            'gross_breakdown' => 'Gross Salary Breakdown',
            'deductions'      => 'Deductions Detail',
            'net_pay'         => 'Net Pay Report',
            'overtime'        => 'Overtime Report',
            'headcount'       => 'Headcount by Department',
            'variance'        => 'Month-on-Month Variance',
            'tax'             => 'Tax Liability',
            'anomalies'       => 'Payroll Anomalies',
            'corrections'     => 'Auto-Corrections',
            'leakage'         => 'Leakage & Fraud Risk',
        ];
    }
}
