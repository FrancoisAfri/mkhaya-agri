<?php

namespace App\Modules\HR\Payroll\Jobs;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Notifications\PayrollReportReadyNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GeneratePayrollReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 180;

    public function __construct(
        private readonly string  $reportKey,
        private readonly ?string $recipientEmail = null,
        private readonly ?int    $runId = null,
    ) {}

    public function handle(): void
    {
        [$headers, $rows] = $this->buildData();

        $filename    = "payroll-{$this->reportKey}-" . now()->format('Y-m-d-His') . '.pdf';
        $storagePath = "reports/{$filename}";

        $html = $this->buildHtml($headers, $rows);
        $pdf  = Pdf::loadHTML($html)->setPaper('a4', 'landscape');

        Storage::put($storagePath, $pdf->output());

        if ($this->recipientEmail) {
            $path = Storage::path($storagePath);
            Mail::raw(
                "Your {$this->reportKey} payroll report is ready. Please find it attached.",
                function ($msg) use ($path, $filename) {
                    $msg->to($this->recipientEmail)
                        ->subject("Payroll Report Ready: {$this->reportKey}")
                        ->attach($path, ['as' => $filename, 'mime' => 'application/pdf']);
                }
            );

            $user = User::where('email', $this->recipientEmail)->first();
            $user?->notify(new PayrollReportReadyNotification($this->reportKey, $filename));
        }
    }

    public function buildData(): array
    {
        $nameExpr = "TRIM(CONCAT(hp.first_name, ' ', COALESCE(hp.surname, '')))";
        $deptExpr = "COALESCE(cu.name, hp.division_level_1, 'Unassigned')";

        return match ($this->reportKey) {
            'payslips' => [
                ['Employee', 'Department', 'Run', 'Period', 'Basic Salary', 'Gross Pay', 'Deductions', 'PAYE', 'UIF', 'Pension', 'Net Pay', 'Status'],
                DB::table('payslips as ps')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, pr.period_start, ps.basic_salary, ps.gross_pay, ps.total_deductions, ps.paye, ps.uif, ps.pension, ps.net_pay, ps.status")
                    ->orderBy('pr.period_start', 'desc')->orderByRaw("{$nameExpr}")
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'gross_breakdown' => [
                ['Employee', 'Department', 'Run', 'Line Type', 'Description', 'Qty', 'Amount'],
                DB::table('payroll_earnings_lines as el')
                    ->join('payslips as ps', 'ps.id', '=', 'el.payslip_id')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->whereNotIn('el.line_type', ['paye', 'uif', 'pension', 'other_deduction'])
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, el.line_type, el.description, el.quantity, el.amount")
                    ->orderBy('pr.period_start', 'desc')->orderByRaw("{$nameExpr}")
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'deductions' => [
                ['Employee', 'Department', 'Run', 'PAYE', 'UIF', 'Pension', 'Total Deductions'],
                DB::table('payslips as ps')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, ps.paye, ps.uif, ps.pension, ps.total_deductions")
                    ->orderBy('pr.period_start', 'desc')->orderByRaw("{$nameExpr}")
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'net_pay' => [
                ['Employee', 'Department', 'Run', 'Period Start', 'Period End', 'Gross Pay', 'Deductions', 'Net Pay'],
                DB::table('payslips as ps')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, pr.period_start, pr.period_end, ps.gross_pay, ps.total_deductions, ps.net_pay")
                    ->orderBy('pr.period_start', 'desc')->orderByRaw("{$nameExpr}")
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'overtime' => [
                ['Employee', 'Department', 'Run', 'Description', 'Hours', 'Amount'],
                DB::table('payroll_earnings_lines as el')
                    ->join('payslips as ps', 'ps.id', '=', 'el.payslip_id')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->where('el.line_type', 'overtime')
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, el.description, el.quantity as hours, el.amount")
                    ->orderBy('pr.period_start', 'desc')
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'headcount' => [
                ['Run', 'Period Start', 'Period End', 'Department', 'Headcount', 'Total Net Pay', 'Avg Net Pay'],
                DB::table('payslips as ps')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("pr.ref_number as run, pr.period_start, pr.period_end, {$deptExpr} as department, COUNT(*) as headcount, SUM(ps.net_pay) as total_net, ROUND(AVG(ps.net_pay), 2) as avg_net")
                    ->groupByRaw("pr.ref_number, pr.period_start, pr.period_end, {$deptExpr}")
                    ->orderBy('pr.period_start', 'desc')
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'variance' => [
                ['Employee', 'Department', 'Previous Net', 'Current Net', 'Variance (R)', 'Variance (%)'],
                $this->buildVarianceData($nameExpr, $deptExpr),
            ],

            'tax' => [
                ['Employee', 'Department', 'Run', 'Gross Pay', 'Taxable Income (est.)', 'PAYE', 'UIF', 'Pension', 'Total Tax'],
                DB::table('payslips as ps')
                    ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'ps.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->when($this->runId, fn($q) => $q->where('ps.payroll_run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, pr.ref_number as run, ps.gross_pay, ROUND(ps.gross_pay - ps.pension, 2) as taxable_income, ps.paye, ps.uif, ps.pension, ROUND(ps.paye + ps.uif + ps.pension, 2) as total_tax")
                    ->orderBy('pr.period_start', 'desc')->orderByRaw("{$nameExpr}")
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'anomalies' => [
                ['Employee', 'Department', 'Run', 'Anomaly Type', 'Severity', 'Description', 'Status', 'Detected At'],
                DB::table('payroll_anomalies as pa')
                    ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pa.run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'pa.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->when($this->runId, fn($q) => $q->where('pa.run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, COALESCE(pr.ref_number, 'N/A') as run, pa.anomaly_type, pa.severity, pa.description, pa.status, pa.detected_at")
                    ->orderBy('pa.detected_at', 'desc')
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'corrections' => [
                ['Employee', 'Run', 'Correction Type', 'Field', 'Original Value', 'Corrected Value', 'Status', 'Applied At'],
                DB::table('payroll_corrections as pc')
                    ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pc.run_id')
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pc.employee_id')
                    ->when($this->runId, fn($q) => $q->where('pc.run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, COALESCE(pr.ref_number, 'N/A') as run, pc.correction_type, pc.field_affected, pc.original_value, pc.corrected_value, pc.status, pc.applied_at")
                    ->orderBy('pc.created_at', 'desc')
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            'leakage' => [
                ['Employee', 'Department', 'Run', 'Anomaly Type', 'Description', 'Net Pay at Risk', 'Severity', 'Status'],
                DB::table('payroll_anomalies as pa')
                    ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pa.run_id')
                    ->leftJoin('payslips as ps', fn($j) => $j->on('ps.employee_id', '=', 'pa.employee_id')->on('ps.payroll_run_id', '=', 'pa.run_id'))
                    ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pa.employee_id')
                    ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'pa.employee_id')
                    ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
                    ->whereIn('pa.anomaly_type', ['ghost_employee', 'duplicate_payment', 'terminated_employee_paid', 'inactive_employee_paid', 'overpayment'])
                    ->when($this->runId, fn($q) => $q->where('pa.run_id', $this->runId))
                    ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, COALESCE(pr.ref_number, 'N/A') as run, pa.anomaly_type, pa.description, COALESCE(ps.net_pay, 0) as net_pay_at_risk, pa.severity, pa.status")
                    ->orderBy('pa.detected_at', 'desc')
                    ->get()->map(fn($r) => (array) $r)->toArray(),
            ],

            default => [['Report', 'Key'], [[$this->reportKey, 'No data builder defined']]],
        };
    }

    private function buildVarianceData(string $nameExpr, string $deptExpr): array
    {
        $runs = DB::table('payroll_runs')->where('status', 'released')->orderBy('period_start', 'desc')->limit(2)->get();
        if ($runs->count() < 2) {
            return [];
        }

        [$curr, $prev] = [$runs[0], $runs[1]];

        return DB::table('payslips as curr')
            ->join('payslips as prev', fn($j) => $j->on('prev.employee_id', '=', 'curr.employee_id')->where('prev.payroll_run_id', $prev->id))
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'curr.employee_id')
            ->leftJoin('company_unit_user_pivots as cup', 'cup.user_id', '=', 'curr.employee_id')
            ->leftJoin('company_units as cu', fn($j) => $j->on('cu.id', '=', 'cup.company_id')->where('cu.type', 'department'))
            ->where('curr.payroll_run_id', $curr->id)
            ->selectRaw("{$nameExpr} as employee, {$deptExpr} as department, ROUND(prev.net_pay, 2) as previous_net, ROUND(curr.net_pay, 2) as current_net, ROUND(curr.net_pay - prev.net_pay, 2) as variance_r, CASE WHEN prev.net_pay > 0 THEN ROUND(((curr.net_pay - prev.net_pay) / prev.net_pay) * 100, 2) ELSE 0 END as variance_pct")
            ->orderByRaw("ABS(curr.net_pay - prev.net_pay) DESC")
            ->get()->map(fn($r) => (array) $r)->toArray();
    }

    private function buildHtml(array $headers, array $rows): string
    {
        $title    = ucwords(str_replace('_', ' ', $this->reportKey)) . ' Payroll Report';
        $date     = now()->format('d M Y');
        $rowsHtml = '';

        foreach ($rows as $row) {
            $row = (array) $row;
            $rowsHtml .= '<tr>';
            foreach ($headers as $h) {
                $key = strtolower(str_replace([' ', '(', ')', '%', 'R '], ['_', '', '', 'pct', ''], $h));
                $val = $row[$key] ?? $row[array_key_first($row)] ?? '-';
                $rowsHtml .= '<td>' . e((string) $val) . '</td>';
            }
            $rowsHtml .= '</tr>';
        }

        $thHtml = implode('', array_map(fn($h) => '<th>' . e($h) . '</th>', $headers));

        return <<<HTML
        <html><head><style>
        body{font-family:Arial,sans-serif;font-size:9px;color:#222;}
        h2{margin:0 0 2px 0;color:#1A237E;font-size:13px;}
        p{margin:0 0 8px 0;color:#666;font-size:9px;}
        table{width:100%;border-collapse:collapse;}
        th,td{border:1px solid #ddd;padding:4px 6px;text-align:left;}
        th{background:#E8EAF6;font-weight:bold;color:#1A237E;}
        tr:nth-child(even){background:#f9f9f9;}
        </style></head><body>
        <h2>$title</h2><p>Generated $date</p>
        <table><thead><tr>$thHtml</tr></thead><tbody>$rowsHtml</tbody></table>
        </body></html>
        HTML;
    }
}
