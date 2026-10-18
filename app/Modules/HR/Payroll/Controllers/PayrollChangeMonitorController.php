<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollChangeRisk;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollChangeMonitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollChangeMonitorController extends Controller
{
    public function __construct(private PayrollChangeMonitorService $monitorService) {}

    public function index(): Response
    {
        $filters = request()->only(['run_id', 'change_type', 'risk_level', 'field']);
        $runId   = $filters['run_id']      ?? '';
        $type    = $filters['change_type'] ?? '';
        $level   = $filters['risk_level']  ?? '';
        $field   = $filters['field']       ?? '';

        $query = DB::table('payroll_change_logs as pcl')
            ->leftJoin('payroll_change_risks as pcr', 'pcr.change_log_id', '=', 'pcl.id')
            ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pcl.run_id')
            ->select(
                'pcl.id', 'pcl.employee_id', 'pcl.field_changed',
                'pcl.old_value', 'pcl.new_value', 'pcl.change_type',
                'pcl.risk_score', 'pcl.risk_level', 'pcl.changed_at', 'pcl.change_reason',
                'pcl.run_id',
                'pr.ref_number as run_ref',
                'pcr.id as risk_id', 'pcr.risk_type', 'pcr.severity', 'pcr.status as risk_status',
                'pcr.risk_description',
            );

        if ($runId) $query->where('pcl.run_id', $runId);
        if ($type)  $query->where('pcl.change_type', $type);
        if ($level) $query->where('pcl.risk_level', $level);
        if ($field) $query->where('pcl.field_changed', 'like', "%{$field}%");

        $changes = $query->orderByDesc('pcl.changed_at')->paginate(25)->withQueryString();

        // Enrich with employee names
        $employeeIds = collect($changes->items())->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $items = collect($changes->items())->map(function ($row) use ($names) {
            $row->employee_name = $names[$row->employee_id]->full_name ?? null;
            return $row;
        });

        $statsRun = $runId
            ? PayrollRun::find($runId)
            : PayrollRun::whereNotIn('status', ['released', 'cancelled'])->latest('period_start')->first();

        $stats = $statsRun
            ? $this->monitorService->getStats($statsRun)
            : ['total' => 0, 'manual' => 0, 'flagged' => 0, 'critical' => 0];

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')->limit(50)->get();

        return Inertia::render('Modules/HR/Payroll/ChangeLog/Index', [
            'changes' => [
                'data' => $items,
                'meta' => [
                    'current_page' => $changes->currentPage(),
                    'last_page'    => $changes->lastPage(),
                    'total'        => $changes->total(),
                ],
            ],
            'filters' => $filters,
            'stats'   => $stats,
            'runs'    => $runs,
        ]);
    }

    public function analyze(PayrollRun $run): RedirectResponse
    {
        $saved = $this->monitorService->runChangeRiskAnalysis($run);

        return back()->with('success', "Risk analysis complete — {$saved} new risk flag(s) for {$run->ref_number}.");
    }

    public function review(PayrollChangeRisk $risk): RedirectResponse
    {
        $risk->update([
            'status'      => 'reviewed',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Risk flagged as reviewed.');
    }

    public function clear(PayrollChangeRisk $risk): RedirectResponse
    {
        $risk->update([
            'status'      => 'cleared',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Risk cleared.');
    }
}
