<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollLeakageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollLeakageController extends Controller
{
    private const LEAKAGE_TYPES = [
        'duplicate_allowance',
        'allowance_leakage',
        'benefit_after_termination',
        'suspicious_low_deductions',
        'recurring_payroll_drift',
    ];

    public function __construct(private PayrollLeakageService $leakageService) {}

    public function index(): Response
    {
        $filters  = request()->only(['status', 'severity', 'type', 'run_id']);
        $runId    = $filters['run_id']    ?? '';
        $status   = $filters['status']   ?? '';
        $severity = $filters['severity'] ?? '';
        $type     = $filters['type']     ?? '';

        $query = PayrollAnomaly::query()
            ->whereIn('anomaly_type', self::LEAKAGE_TYPES)
            ->with('payrollRun:id,uuid,ref_number,name');

        if ($runId)    $query->where('run_id', $runId);
        if ($status)   $query->where('status', $status);
        if ($severity) $query->where('severity', $severity);
        if ($type)     $query->where('anomaly_type', $type);

        $findings = $query->latest('detected_at')->paginate(25)->withQueryString();

        // Enrich with employee names
        $employeeIds = $findings->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $findings->getCollection()->transform(function ($f) use ($names) {
            $f->employee_name = $names[$f->employee_id]->full_name ?? null;
            // Extract leakage_amount from metadata for display
            $meta = is_array($f->metadata) ? $f->metadata : [];
            $f->leakage_amount = (float) ($meta['leakage_amount'] ?? $meta['excess'] ?? $meta['amount'] ?? 0);
            return $f;
        });

        // Dashboard card data
        $estimate = $this->leakageService->estimatePayrollLeakage(1);

        // Aggregate leakage amounts by type (open only)
        $byType = PayrollAnomaly::whereIn('anomaly_type', self::LEAKAGE_TYPES)
            ->where('status', 'open')
            ->select('anomaly_type', DB::raw('COUNT(*) as count'))
            ->groupBy('anomaly_type')
            ->get()
            ->keyBy('anomaly_type');

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')
            ->limit(50)
            ->get();

        return Inertia::render('Modules/HR/Payroll/Leakage/Index', [
            'findings'    => $findings,
            'filters'     => $filters,
            'estimate'    => $estimate,
            'byType'      => $byType,
            'runs'        => $runs,
            'leakageTypes' => self::LEAKAGE_TYPES,
        ]);
    }

    public function run(PayrollRun $run): RedirectResponse
    {
        $saved = $this->leakageService->runLeakageDetection($run);

        return back()->with('success', "Leakage detection complete — {$saved} new finding(s) recorded for {$run->ref_number}.");
    }
}
