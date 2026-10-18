<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollBehaviorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollBehaviorController extends Controller
{
    private const BEHAVIOR_TYPES = [
        'abnormal_approval_behavior',
        'repeated_payroll_overrides',
        'after_hours_payroll_change',
        'department_payroll_spike',
    ];

    public function __construct(private PayrollBehaviorService $behaviorService) {}

    public function index(): Response
    {
        $filters  = request()->only(['status', 'severity', 'type', 'run_id']);
        $runId    = $filters['run_id']    ?? '';
        $status   = $filters['status']   ?? '';
        $severity = $filters['severity'] ?? '';
        $type     = $filters['type']     ?? '';

        $query = PayrollAnomaly::query()
            ->whereIn('anomaly_type', self::BEHAVIOR_TYPES)
            ->with('payrollRun:id,uuid,ref_number,name');

        if ($runId)    $query->where('run_id', $runId);
        if ($status)   $query->where('status', $status);
        if ($severity) $query->where('severity', $severity);
        if ($type)     $query->where('anomaly_type', $type);

        $anomalies = $query->latest('detected_at')->paginate(25)->withQueryString();

        // Enrich employee names where applicable
        $employeeIds = $anomalies->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $anomalies->getCollection()->transform(function ($a) use ($names) {
            $a->employee_name = $names[$a->employee_id]->full_name ?? null;
            return $a;
        });

        $summary = $this->behaviorService->getSummary();

        // Per-user change breakdown for the most recent non-released run
        $latestActiveRun = PayrollRun::whereNotIn('status', ['released', 'cancelled'])
            ->latest('period_start')->first();
        $changesByUser = $latestActiveRun
            ? $this->behaviorService->getChangesByUser($latestActiveRun)
            : collect();

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')->limit(50)->get();

        return Inertia::render('Modules/HR/Payroll/Behavior/Index', [
            'anomalies'     => $anomalies,
            'filters'       => $filters,
            'stats'         => $summary,
            'changesByUser' => $changesByUser,
            'runs'          => $runs,
            'behaviorTypes' => self::BEHAVIOR_TYPES,
        ]);
    }

    public function run(PayrollRun $run): RedirectResponse
    {
        $saved = $this->behaviorService->runBehaviorAnalysis($run);

        return back()->with('success', "Behaviour analysis complete — {$saved} new pattern(s) detected for {$run->ref_number}.");
    }
}
