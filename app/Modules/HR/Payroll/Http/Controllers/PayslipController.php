<?php

namespace App\Modules\HR\Payroll\Http\Controllers;

use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Enums\PayslipStatus;
use App\Modules\HR\Payroll\Models\PayrollEarningsLine;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayslipController
{
    public function mine(Request $request): Response
    {
        $employeeId = auth()->id();

        $payslips = Payslip::where('employee_id', $employeeId)
            ->with('payrollRun:id,uuid,name,period_start,period_end,status,released_at')
            ->orderByDesc('period_start')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'      => Payslip::where('employee_id', $employeeId)->count(),
            'latest_net' => Payslip::where('employee_id', $employeeId)
                ->whereHas('payrollRun', fn ($q) => $q->where('status', 'released'))
                ->orderByDesc('period_start')
                ->value('net_pay'),
            'ytd_net'    => Payslip::where('employee_id', $employeeId)
                ->whereHas('payrollRun', fn ($q) => $q->where('status', 'released'))
                ->whereYear('period_start', now()->year)
                ->sum('net_pay'),
        ];

        return Inertia::render('Modules/Payroll/MyPayslips', [
            'payslips' => $payslips,
            'stats'    => $stats,
        ]);
    }

    public function show(PayrollRun $run, Payslip $payslip): Response
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        $payslip->load('earningsLines');

        if ($payslip->status === PayslipStatus::Draft && $run->status->value === 'released') {
            $payslip->update([
                'status'    => PayslipStatus::Viewed->value,
                'viewed_at' => now(),
            ]);
        }

        $employee = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $payslip->employee_id)
            ->selectRaw("COALESCE(TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))), u.email) AS name")
            ->first();

        return Inertia::render('Modules/Payroll/Payslip', [
            'run'           => $run,
            'payslip'       => $payslip,
            'earningsLines' => $payslip->earningsLines,
            'employeeName'  => $employee?->name ?? 'Unknown',
        ]);
    }

    public function store(Request $request, PayrollRun $run): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id'   => 'required|integer|exists:users,id',
            'basic_salary'  => 'required|numeric|min:0',
        ]);

        $basic = (float) $validated['basic_salary'];

        // Tax calculations
        $paye    = $basic > 8333 ? $basic * 0.25 : $basic * 0.18;
        $uif     = min(148.72, $basic * 0.01);
        $pension = $basic * 0.075;

        $grossPay        = $basic;
        $totalDeductions = $paye + $uif + $pension;
        $netPay          = $grossPay - $totalDeductions;

        $payslip = Payslip::create([
            'payroll_run_id'   => $run->id,
            'employee_id'      => $validated['employee_id'],
            'basic_salary'     => $basic,
            'gross_pay'        => $grossPay,
            'total_deductions' => $totalDeductions,
            'net_pay'          => $netPay,
            'paye'             => $paye,
            'uif'              => $uif,
            'pension'          => $pension,
            'period_start'     => $run->period_start,
            'period_end'       => $run->period_end,
            'issued_at'        => now(),
        ]);

        // Create earnings lines
        PayrollEarningsLine::create([
            'payslip_id'  => $payslip->id,
            'line_type'   => EarningsLineType::BasicSalary->value,
            'description' => 'Basic Salary',
            'amount'      => $basic,
            'quantity'    => 1,
        ]);

        PayrollEarningsLine::create([
            'payslip_id'  => $payslip->id,
            'line_type'   => EarningsLineType::Paye->value,
            'description' => 'PAYE (Income Tax)',
            'amount'      => $paye,
            'quantity'    => 1,
        ]);

        PayrollEarningsLine::create([
            'payslip_id'  => $payslip->id,
            'line_type'   => EarningsLineType::Uif->value,
            'description' => 'UIF Contribution',
            'amount'      => $uif,
            'quantity'    => 1,
        ]);

        PayrollEarningsLine::create([
            'payslip_id'  => $payslip->id,
            'line_type'   => EarningsLineType::Pension->value,
            'description' => 'Pension Fund',
            'amount'      => $pension,
            'quantity'    => 1,
        ]);

        // Update run totals
        $run->update([
            'total_gross'      => $run->payslips()->sum('gross_pay'),
            'total_deductions' => $run->payslips()->sum('total_deductions'),
            'total_net'        => $run->payslips()->sum('net_pay'),
            'employee_count'   => $run->payslips()->count(),
        ]);

        return back();
    }

    public function addLine(Request $request, PayrollRun $run, Payslip $payslip): RedirectResponse
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);

        $earningsLineTypeValues = array_column(EarningsLineType::cases(), 'value');

        $validated = $request->validate([
            'line_type'   => 'required|in:' . implode(',', $earningsLineTypeValues),
            'description' => 'required|string|max:200',
            'amount'      => 'required|numeric',
            'quantity'    => 'nullable|numeric|min:0',
        ]);

        PayrollEarningsLine::create([
            'payslip_id'  => $payslip->id,
            'line_type'   => $validated['line_type'],
            'description' => $validated['description'],
            'amount'      => $validated['amount'],
            'quantity'    => $validated['quantity'] ?? 1,
        ]);

        $this->recalculatePayslip($payslip);

        return back();
    }

    public function removeLine(PayrollRun $run, Payslip $payslip, PayrollEarningsLine $line): RedirectResponse
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        abort_unless($line->payslip_id === $payslip->id, 404);

        $line->delete();

        $this->recalculatePayslip($payslip);

        return back();
    }

    public function download(PayrollRun $run, Payslip $payslip): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        abort_unless($run->status === PayrollRunStatus::Released, 403, 'Payslip only available for released runs.');

        // Prefer the stored, encrypted (locked) PDF — only the employee's payslip
        // key opens it. Fall back to rendering one on the fly for legacy runs.
        if ($payslip->hasBytes()) {
            return response((string) $payslip->bytes(), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"payslip_{$payslip->uuid}.pdf\"",
            ]);
        }

        $payslip->load('earningsLines');
        $payslip->employee_name = $this->resolveEmployeeName($payslip->employee_id);

        $person = \App\Modules\HR\Hr\Models\HRPerson::where('user_id', $payslip->employee_id)->first();
        $html = view('pdf.payslip', ['payslip' => $payslip])->render();
        $secure = app(\App\Modules\HR\Payroll\Services\SecurePayslipService::class);

        // Lock it if the employee has a key; otherwise serve unlocked (they can set one).
        $pdf = ($person && $person->hasPayslipKey())
            ? $secure->renderProtected($html, $person->payslipKey())
            : $secure->render($html);

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"payslip_{$payslip->uuid}.pdf\"",
        ]);
    }

    /**
     * The signed-in employee generates (or rotates) their payslip key. The
     * plaintext is shown once via flash; all their locked payslips open with it.
     */
    public function generateKey(Request $request): RedirectResponse
    {
        $person = \App\Modules\HR\Hr\Models\HRPerson::where('user_id', $request->user()->id)->firstOrFail();
        $key = app(\App\Modules\HR\Payroll\Services\SecurePayslipService::class)->generateKey();
        $person->payslip_key = $key;
        $person->save();

        return back()->with('payslip_key', $key)
            ->with('success', 'Payslip key generated. Use it to open your downloaded payslips. It will not be shown again.');
    }

    private function resolveEmployeeName(int $id): string
    {
        $row = DB::table('users as u')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.id', $id)
            ->selectRaw("COALESCE(TRIM(CONCAT(p.first_name,' ',COALESCE(p.surname,''))), u.email) AS name")
            ->first();
        return $row?->name ?? "User #{$id}";
    }

    private function recalculatePayslip(Payslip $payslip): void
    {
        // Route through the shared tax engine so a manually-added line (allowance,
        // overtime, deduction…) is taxed exactly like the "Calculate" button does —
        // gross includes basic + earnings lines, and PAYE/UIF/pension come from SARS.
        app(\App\Modules\HR\Payroll\Services\PayrollService::class)
            ->calculateAllPayslips($payslip->payrollRun, (int) (auth()->id() ?? 0));
    }
}
