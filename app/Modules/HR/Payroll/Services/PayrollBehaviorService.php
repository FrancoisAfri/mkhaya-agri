<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payroll Behaviour Analytics Engine (Phase 6)
 *
 * Detects suspicious patterns in WHO does payroll work and HOW they do it:
 *   1. Abnormal approval behaviour    — approving runs with critical anomalies
 *   2. Repeated manual overrides      — >3 manual changes by one user in a run
 *   3. After-hours payroll changes    — edits outside 07:00–19:00 (PostgreSQL EXTRACT)
 *   4. Department payroll spike       — dept net pay up >15% vs previous released run
 *
 * All findings are persisted to payroll_anomalies (no new table needed).
 */
class PayrollBehaviorService
{
    private const SEVERITY = [
        'abnormal_approval_behavior'  => 'high',
        'repeated_payroll_overrides'  => 'medium',
        'after_hours_payroll_change'  => 'medium',
        'department_payroll_spike'    => 'high',
    ];

    // ── Orchestrator ──────────────────────────────────────────────────────────

    public function analyzePayrollBehaviorPatterns(PayrollRun $run): array
    {
        return array_merge(
            $this->detectAbnormalApprovalBehavior($run),
            $this->detectRepeatedPayrollOverrides($run),
            $this->detectAfterHoursPayrollChanges($run),
            $this->detectDepartmentPayrollSpike($run),
        );
    }

    public function runBehaviorAnalysis(PayrollRun $run): int
    {
        $results = $this->analyzePayrollBehaviorPatterns($run);
        return $this->saveResults($run, $results);
    }

    // ── 1. Approver approved runs that had critical anomalies ─────────────────

    public function detectAbnormalApprovalBehavior(PayrollRun $run): array
    {
        try {
            // Approvers who acted on THIS run's approval
            // approval_steps.approval_id (not payroll_approval_id)
            // approvals.run_id (not payroll_run_id)
            // approval_steps.action (not status)
            $approverIds = DB::table('payroll_approval_steps as pas')
                ->join('payroll_approvals as pa', 'pa.id', '=', 'pas.approval_id')
                ->where('pa.run_id', $run->id)
                ->where('pas.action', 'approved')
                ->pluck('pas.approver_id')
                ->unique();

            if ($approverIds->isEmpty()) {
                return [];
            }

            // Enrich approver names
            $approverNames = DB::table('hr_people')
                ->whereIn('user_id', $approverIds)
                ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
                ->get()->keyBy('user_id');

            $anomalies = [];
            foreach ($approverIds as $approverId) {
                // Count distinct runs this approver approved that had critical/open anomalies
                $criticalRuns = DB::table('payroll_approval_steps as pas')
                    ->join('payroll_approvals as pa', 'pa.id', '=', 'pas.approval_id')
                    ->join('payroll_anomalies as pan', 'pan.run_id', '=', 'pa.run_id')
                    ->where('pas.approver_id', $approverId)
                    ->where('pas.action', 'approved')
                    ->where('pan.severity', 'critical')
                    ->where('pan.status', 'open')
                    ->distinct('pa.run_id')
                    ->count('pa.run_id');

                if ($criticalRuns > 0) {
                    $name = $approverNames[$approverId]->full_name ?? "User #{$approverId}";
                    $anomalies[] = [
                        'employee_id'   => null,
                        'anomaly_type'  => 'abnormal_approval_behavior',
                        'description'   => "{$name} approved {$criticalRuns} payroll run(s) that contained open critical anomalies without resolving them first.",
                        'severity'      => self::SEVERITY['abnormal_approval_behavior'],
                        'metadata'      => [
                            'approver_id'          => $approverId,
                            'approver_name'        => $name,
                            'critical_runs_approved' => $criticalRuns,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectAbnormalApprovalBehavior: ' . $e->getMessage());
            return [];
        }
    }

    // ── 2. Repeated manual overrides by a single user ─────────────────────────

    public function detectRepeatedPayrollOverrides(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payroll_change_logs as pcl')
                ->join('hr_people as hp', 'hp.user_id', '=', 'pcl.changed_by')
                ->where('pcl.run_id', $run->id)
                ->where('pcl.change_type', 'manual')
                ->select(
                    'pcl.changed_by',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS changer_name"),
                    DB::raw('COUNT(*) as change_count'),
                    DB::raw("STRING_AGG(DISTINCT pcl.field_changed, ', ') AS fields_changed"),
                )
                ->groupBy('pcl.changed_by', 'hp.first_name', 'hp.surname')
                ->havingRaw('COUNT(*) > 3')
                ->get();

            return $rows->map(fn ($row) => [
                'employee_id'  => null,
                'anomaly_type' => 'repeated_payroll_overrides',
                'description'  => "{$row->changer_name} made {$row->change_count} manual payroll overrides in run {$run->ref_number} (threshold: 3). Fields: {$row->fields_changed}.",
                'severity'     => self::SEVERITY['repeated_payroll_overrides'],
                'metadata'     => [
                    'changed_by'    => $row->changed_by,
                    'changer_name'  => $row->changer_name,
                    'change_count'  => $row->change_count,
                    'fields_changed' => $row->fields_changed,
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('detectRepeatedPayrollOverrides: ' . $e->getMessage());
            return [];
        }
    }

    // ── 3. Payroll changes made outside business hours ────────────────────────

    public function detectAfterHoursPayrollChanges(PayrollRun $run): array
    {
        try {
            // PostgreSQL: EXTRACT(HOUR FROM ...) — NOT MySQL HOUR()
            $rows = DB::table('payroll_change_logs as pcl')
                ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pcl.changed_by')
                ->where('pcl.run_id', $run->id)
                ->where(function ($q) {
                    $q->whereRaw('EXTRACT(HOUR FROM pcl.changed_at) < 7')
                      ->orWhereRaw('EXTRACT(HOUR FROM pcl.changed_at) >= 19');
                })
                ->select(
                    'pcl.id',
                    'pcl.changed_by',
                    'pcl.changed_at',
                    'pcl.field_changed',
                    'pcl.employee_id',
                    'pcl.change_type',
                    DB::raw("CONCAT(hp.first_name, ' ', hp.surname) AS changer_name"),
                )
                ->orderBy('pcl.changed_at')
                ->get();

            return $rows->map(function ($row) {
                $changedAt = $row->changed_at;
                $name      = $row->changer_name ?? "User #{$row->changed_by}";
                return [
                    'employee_id'  => $row->employee_id ?? null,
                    'anomaly_type' => 'after_hours_payroll_change',
                    'description'  => "{$name} modified '{$row->field_changed}' outside business hours at {$changedAt}.",
                    'severity'     => self::SEVERITY['after_hours_payroll_change'],
                    'metadata'     => [
                        'changed_by'    => $row->changed_by,
                        'changer_name'  => $name,
                        'changed_at'    => $changedAt,
                        'field_changed' => $row->field_changed,
                        'change_type'   => $row->change_type,
                    ],
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('detectAfterHoursPayrollChanges: ' . $e->getMessage());
            return [];
        }
    }

    // ── 4. Sudden department payroll spike vs previous released run ───────────

    public function detectDepartmentPayrollSpike(PayrollRun $run): array
    {
        try {
            // Previous released run immediately before this one
            $prevRun = PayrollRun::where('status', 'released')
                ->where('period_end', '<', $run->period_start)
                ->latest('period_end')
                ->first();

            if (!$prevRun) {
                return [];
            }

            // Current run: net pay grouped by hr_people.division_level_1
            $current = $this->deptTotals($run->id);
            if ($current->isEmpty()) {
                return [];
            }

            // Previous run: net pay grouped by hr_people.division_level_1
            $previous = $this->deptTotals($prevRun->id)->keyBy('department');

            $threshold = 0.15; // 15% spike threshold
            $anomalies = [];

            foreach ($current as $row) {
                $dept    = $row->department;
                $currNet = (float) $row->total_net;
                $prevNet = (float) ($previous[$dept]->total_net ?? 0);

                if ($prevNet <= 0) {
                    continue; // Cannot compare — no prior data for this dept
                }

                $changePct = ($currNet - $prevNet) / $prevNet;

                if ($changePct > $threshold) {
                    $anomalies[] = [
                        'employee_id'  => null,
                        'anomaly_type' => 'department_payroll_spike',
                        'description'  => "Department '{$dept}' payroll jumped " . round($changePct * 100, 1) . "% (R{$prevNet} → R{$currNet}) vs previous run {$prevRun->ref_number}.",
                        'severity'     => self::SEVERITY['department_payroll_spike'],
                        'metadata'     => [
                            'department'        => $dept,
                            'current_net'       => round($currNet, 2),
                            'previous_net'      => round($prevNet, 2),
                            'change_pct'        => round($changePct * 100, 1),
                            'threshold_pct'     => $threshold * 100,
                            'previous_run_ref'  => $prevRun->ref_number,
                            'headcount'         => $row->headcount,
                        ],
                    ];
                }
            }
            return $anomalies;
        } catch (\Throwable $e) {
            Log::error('detectDepartmentPayrollSpike: ' . $e->getMessage());
            return [];
        }
    }

    private function deptTotals(int $runId): \Illuminate\Support\Collection
    {
        return DB::table('payslips as ps')
            ->join('hr_people as hp', 'hp.user_id', '=', 'ps.employee_id')
            ->where('ps.payroll_run_id', $runId)
            ->whereNotNull('hp.division_level_1')
            ->where('hp.division_level_1', '!=', '')
            ->select(
                'hp.division_level_1 as department',
                DB::raw('SUM(ps.net_pay) as total_net'),
                DB::raw('COUNT(DISTINCT ps.employee_id) as headcount'),
            )
            ->groupBy('hp.division_level_1')
            ->get();
    }

    // ── Per-user change summary (dashboard use) ───────────────────────────────

    public function getChangesByUser(PayrollRun $run): \Illuminate\Support\Collection
    {
        return DB::table('payroll_change_logs as pcl')
            ->leftJoin('hr_people as hp', 'hp.user_id', '=', 'pcl.changed_by')
            ->where('pcl.run_id', $run->id)
            ->select(
                'pcl.changed_by',
                DB::raw("COALESCE(CONCAT(hp.first_name, ' ', hp.surname), 'User #' || pcl.changed_by::text) AS changer_name"),
                DB::raw('COUNT(*) as total_changes'),
                DB::raw("COUNT(*) FILTER (WHERE pcl.change_type = 'manual') AS manual_changes"),
                DB::raw("MAX(pcl.risk_score) AS max_risk_score"),
            )
            ->groupBy('pcl.changed_by', 'hp.first_name', 'hp.surname')
            ->orderByDesc('total_changes')
            ->get();
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function saveResults(PayrollRun $run, array $results): int
    {
        $saved = 0;
        $now   = now();

        foreach ($results as $r) {
            $existing = PayrollAnomaly::where('run_id', $run->id)
                ->where('anomaly_type', $r['anomaly_type'])
                ->where('employee_id', $r['employee_id'] ?? null)
                ->first();

            if ($existing) {
                continue;
            }

            PayrollAnomaly::create([
                'uuid'         => Str::uuid(),
                'run_id'       => $run->id,
                'employee_id'  => $r['employee_id'] ?? null,
                'anomaly_type' => $r['anomaly_type'],
                'description'  => $r['description'],
                'severity'     => $r['severity'],
                'status'       => 'open',
                'detected_at'  => $now,
                'metadata'     => $r['metadata'] ?? null,
            ]);
            $saved++;
        }

        return $saved;
    }

    // ── Stats summary ─────────────────────────────────────────────────────────

    public function getSummary(): array
    {
        $types = array_keys(self::SEVERITY);
        $row   = DB::table('payroll_anomalies')
            ->whereIn('anomaly_type', $types)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'open')          AS total_open,
                COUNT(*) FILTER (WHERE severity = 'high')        AS total_high,
                COUNT(*) FILTER (WHERE anomaly_type = 'department_payroll_spike') AS total_spikes,
                COUNT(*) FILTER (WHERE status = 'resolved')      AS total_resolved
            ")
            ->first();

        return [
            'total_open'     => (int) ($row->total_open     ?? 0),
            'total_high'     => (int) ($row->total_high     ?? 0),
            'total_spikes'   => (int) ($row->total_spikes   ?? 0),
            'total_resolved' => (int) ($row->total_resolved ?? 0),
        ];
    }
}
