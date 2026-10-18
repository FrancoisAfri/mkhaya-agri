<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollCrossCheckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollCrossCheckController extends Controller
{
    private const CROSS_CHECK_TYPES = [
        'zero_attendance',
        'unpaid_leave_full_pay',
        'unpaid_leave_in_period',
        'excessive_absence_no_leave',
        'overtime_claim_mismatch',
        'contract_ends_in_period',
        'non_active_employee_in_payroll',
        'allowance_with_zero_basic',
        'acting_allowance_ineligible',
        'shift_allowance_ineligible',
    ];

    public function __construct(private PayrollCrossCheckService $crossCheckService) {}

    public function index(): Response
    {
        $filters  = request()->only(['status', 'severity', 'type', 'run_id']);
        $runId    = $filters['run_id']    ?? '';
        $status   = $filters['status']   ?? '';
        $severity = $filters['severity'] ?? '';
        $type     = $filters['type']     ?? '';

        $query = PayrollAnomaly::query()
            ->whereIn('anomaly_type', self::CROSS_CHECK_TYPES)
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

        // Stats
        $stats = PayrollAnomaly::whereIn('anomaly_type', self::CROSS_CHECK_TYPES)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'open') as total_open,
                COUNT(*) FILTER (WHERE severity = 'high')     as total_high,
                COUNT(*) FILTER (WHERE severity = 'critical') as total_critical,
                COUNT(*) FILTER (WHERE status = 'resolved')   as total_resolved
            ")
            ->first();

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')
            ->limit(50)
            ->get();

        return Inertia::render('Modules/HR/Payroll/CrossChecks/Index', [
            'anomalies'  => $anomalies,
            'filters'    => $filters,
            'stats'      => [
                'total_open'     => (int) ($stats->total_open ?? 0),
                'total_high'     => (int) ($stats->total_high ?? 0),
                'total_critical' => (int) ($stats->total_critical ?? 0),
                'total_resolved' => (int) ($stats->total_resolved ?? 0),
            ],
            'runs'        => $runs,
            'checkTypes'  => self::CROSS_CHECK_TYPES,
        ]);
    }

    public function run(PayrollRun $run): RedirectResponse
    {
        $saved = $this->crossCheckService->runAllCrossChecks($run);

        return back()->with('success', "Cross-check complete — {$saved} new finding(s) recorded for {$run->ref_number}.");
    }
}
