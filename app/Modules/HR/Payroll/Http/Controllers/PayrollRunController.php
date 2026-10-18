<?php

namespace App\Modules\HR\Payroll\Http\Controllers;

use App\Modules\HR\Payroll\AI\PayrollAnomalyAI;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PayrollRunController
{
    public function index(Request $request): Response
    {
        $runs = PayrollRun::withCount('payslips')
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total_runs'          => PayrollRun::count(),
            'total_released'      => PayrollRun::where('status', PayrollRunStatus::Released->value)->count(),
            'current_month_net'   => PayrollRun::whereYear('period_start', now()->year)
                ->whereMonth('period_start', now()->month)
                ->sum('total_net'),
        ];

        return Inertia::render('Modules/Payroll/Index', [
            'runs'    => $runs,
            'filters' => $request->only('status'),
            'stats'   => $stats,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:200',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start',
            'notes'        => 'nullable|string',
        ]);

        $run = PayrollRun::create([
            ...$validated,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('payroll.runs.show', $run);
    }

    public function show(PayrollRun $run): Response
    {
        $run->load('payslips');

        $payslipsWithEmployees = $run->payslips->map(function ($payslip) {
            $employee = DB::table('users as u')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
                ->where('u.id', $payslip->employee_id)
                ->selectRaw("COALESCE(TRIM(CONCAT(p.first_name, ' ', COALESCE(p.surname, ''))), u.email) AS name")
                ->first();

            return array_merge($payslip->toArray(), [
                'employee_name' => $employee?->name ?? 'Unknown',
            ]);
        });

        return Inertia::render('Modules/Payroll/Show', [
            'run'      => $run,
            'payslips' => $payslipsWithEmployees,
        ]);
    }

    public function release(PayrollRun $run): RedirectResponse
    {
        abort_if(! $run->status->canRelease(), 403, 'Only runs in Review status can be released.');

        $run->update([
            'status'      => PayrollRunStatus::Released->value,
            'released_at' => now(),
            'released_by' => auth()->id(),
        ]);

        return back();
    }

    public function cancel(PayrollRun $run): RedirectResponse
    {
        abort_if($run->status->isTerminal(), 403, 'This payroll run is already in a terminal state.');

        $run->update([
            'status' => PayrollRunStatus::Cancelled->value,
        ]);

        return back();
    }

    public function runAnomalyCheck(PayrollRun $run): RedirectResponse
    {
        $run->load(['payslips.earningsLines']);

        $currentRunData = $run->payslips->map(fn ($payslip) => [
            'employee_id'      => $payslip->employee_id,
            'basic_salary'     => $payslip->basic_salary,
            'gross_pay'        => $payslip->gross_pay,
            'total_deductions' => $payslip->total_deductions,
            'net_pay'          => $payslip->net_pay,
            'paye'             => $payslip->paye,
            'uif'              => $payslip->uif,
            'pension'          => $payslip->pension,
            'earnings_lines'   => $payslip->earningsLines->map(fn ($line) => [
                'line_type'   => $line->line_type->value,
                'description' => $line->description,
                'amount'      => $line->amount,
                'quantity'    => $line->quantity,
            ])->toArray(),
        ])->toArray();

        $ai = app(PayrollAnomalyAI::class);
        $result = $ai->detectAnomalies($currentRunData);

        if ($result->failed()) {
            Log::warning('PayrollAnomalyAI: anomaly detection failed for run ' . $run->id, [
                'run_id' => $run->id,
            ]);

            return back();
        }

        $content = json_decode($result->content, true);

        $run->update([
            'ai_risk_level'       => $content['risk_level'] ?? null,
            'ai_anomaly_summary'  => $content,
        ]);

        return back();
    }
}
