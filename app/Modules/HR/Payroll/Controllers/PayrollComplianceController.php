<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollComplianceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollComplianceController extends Controller
{
    private const COMPLIANCE_TYPES = [
        'overtime_law_violation',
        'minimum_wage_violation',
        'leave_compliance_issue',
        'contract_payroll_mismatch',
        'tax_compliance_issue',
        'overtime_rate_violation',
    ];

    public function __construct(private PayrollComplianceService $complianceService) {}

    public function index(): Response
    {
        $filters  = request()->only(['status', 'severity', 'type', 'run_id']);
        $runId    = $filters['run_id']    ?? '';
        $status   = $filters['status']   ?? '';
        $severity = $filters['severity'] ?? '';
        $type     = $filters['type']     ?? '';

        $query = PayrollAnomaly::query()
            ->whereIn('anomaly_type', self::COMPLIANCE_TYPES)
            ->with('payrollRun:id,uuid,ref_number,name');

        if ($runId)    $query->where('run_id', $runId);
        if ($status)   $query->where('status', $status);
        if ($severity) $query->where('severity', $severity);
        if ($type)     $query->where('anomaly_type', $type);

        $anomalies = $query->latest('detected_at')->paginate(25)->withQueryString();

        // Enrich with employee names
        $employeeIds = $anomalies->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $anomalies->getCollection()->transform(function ($a) use ($names) {
            $a->employee_name = $names[$a->employee_id]->full_name ?? null;
            return $a;
        });

        $summary = $this->complianceService->getSummary();

        $stats = [
            'total_open'     => (int) ($summary->total_open     ?? 0),
            'total_critical' => (int) ($summary->total_critical ?? 0),
            'total_high'     => (int) ($summary->total_high     ?? 0),
            'total_resolved' => (int) ($summary->total_resolved ?? 0),
        ];

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')->limit(50)->get();

        return Inertia::render('Modules/HR/Payroll/Compliance/Index', [
            'anomalies'       => $anomalies,
            'filters'         => $filters,
            'stats'           => $stats,
            'runs'            => $runs,
            'complianceTypes' => self::COMPLIANCE_TYPES,
        ]);
    }

    public function run(PayrollRun $run): RedirectResponse
    {
        $saved = $this->complianceService->runComplianceChecks($run);

        return back()->with('success', "Compliance check complete — {$saved} new violation(s) detected for {$run->ref_number}.");
    }
}
