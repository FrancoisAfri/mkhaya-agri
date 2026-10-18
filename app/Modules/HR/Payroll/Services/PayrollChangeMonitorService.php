<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollChangeLog;
use App\Modules\HR\Payroll\Models\PayrollChangeRisk;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollChangeMonitorService
{
    private const FIELD_RISK_SCORES = [
        'bank'         => 90,
        'account'      => 90,
        'net_pay'      => 80,
        'basic_salary' => 70,
        'deduction'    => 60,
        'allowance'    => 50,
    ];
    private const MANUAL_RISK_BONUS   = 20;
    private const HIGH_RISK_THRESHOLD = 70;

    public function logPayrollChange(array $data): PayrollChangeLog
    {
        $score     = $this->calculateChangeRiskScore($data);
        $riskLevel = $this->deriveRiskLevel($score);

        $log = PayrollChangeLog::create(array_merge($data, [
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'changed_at' => $data['changed_at'] ?? now(),
        ]));

        if ($score >= self::HIGH_RISK_THRESHOLD) {
            $this->saveChangeRisk(
                $log,
                'high_risk_field_edit',
                "High-risk field '{$log->field_changed}' changed manually (score {$score}).",
                $riskLevel === 'critical' ? 'critical' : 'high',
            );
        }

        return $log;
    }

    public function detectHighRiskPayrollEdit(PayrollChangeLog $log): bool
    {
        return (int) $log->risk_score >= self::HIGH_RISK_THRESHOLD;
    }

    public function detectBankChangeBeforePayroll(int $employeeId, PayrollRun $run): bool
    {
        try {
            $windowStart = $run->period_start->subDays(7)->toDateString();
            $payDate     = $run->pay_date?->toDateString() ?? $run->period_end->toDateString();

            return DB::table('payroll_change_logs')
                ->where('employee_id', $employeeId)
                ->where(function ($q) {
                    $q->where('field_changed', 'like', '%bank%')
                      ->orWhere('field_changed', 'like', '%account%');
                })
                ->whereBetween('changed_at', [$windowStart, $payDate])
                ->exists();
        } catch (\Throwable $e) {
            Log::error('detectBankChangeBeforePayroll error: ' . $e->getMessage());
            return false;
        }
    }

    public function detectTaxChangeBeforePayroll(int $employeeId, PayrollRun $run): bool
    {
        try {
            $currentPayslip = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('employee_id', $employeeId)
                ->first();

            if (!$currentPayslip) return false;

            $previousRun = DB::table('payroll_runs')
                ->where('status', 'released')
                ->where('period_start', '<', $run->period_start)
                ->orderByDesc('period_start')
                ->first();

            if (!$previousRun) return false;

            $previousPayslip = DB::table('payslips')
                ->where('payroll_run_id', $previousRun->id)
                ->where('employee_id', $employeeId)
                ->first();

            if (!$previousPayslip || (float) $previousPayslip->paye <= 0) return false;

            $changePct = abs((float) $currentPayslip->paye - (float) $previousPayslip->paye)
                / (float) $previousPayslip->paye;

            return $changePct > 0.20;
        } catch (\Throwable $e) {
            Log::error('detectTaxChangeBeforePayroll error: ' . $e->getMessage());
            return false;
        }
    }

    public function detectManualAllowanceChange(PayrollRun $run): array
    {
        try {
            return DB::table('payroll_change_logs')
                ->where('run_id', $run->id)
                ->where('change_type', 'manual')
                ->where('field_changed', 'like', '%allowance%')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->toArray();
        } catch (\Throwable $e) {
            Log::error('detectManualAllowanceChange error: ' . $e->getMessage());
            return [];
        }
    }

    public function detectManualDeductionRemoval(PayrollRun $run): array
    {
        try {
            return DB::table('payroll_change_logs')
                ->where('run_id', $run->id)
                ->where('change_type', 'manual')
                ->where('field_changed', 'like', '%deduction%')
                ->whereRaw("CAST(new_value AS DECIMAL(15,2)) < CAST(old_value AS DECIMAL(15,2))")
                ->get()
                ->map(fn ($row) => (array) $row)
                ->toArray();
        } catch (\Throwable $e) {
            Log::error('detectManualDeductionRemoval error: ' . $e->getMessage());
            return [];
        }
    }

    public function calculateChangeRiskScore(array $changeData): int
    {
        $field      = strtolower($changeData['field_changed'] ?? '');
        $changeType = strtolower($changeData['change_type'] ?? '');

        $baseScore = 20;

        foreach (self::FIELD_RISK_SCORES as $keyword => $score) {
            if (str_contains($field, $keyword)) {
                $baseScore = $score;
                break;
            }
        }

        if ($changeType === 'manual') {
            $baseScore += self::MANUAL_RISK_BONUS;
        }

        return min(100, $baseScore);
    }

    public function runChangeRiskAnalysis(PayrollRun $run): int
    {
        $saved = 0;

        // Manual allowance changes
        foreach ($this->detectManualAllowanceChange($run) as $change) {
            $log = PayrollChangeLog::find($change['id'] ?? null);
            if (!$log) continue;
            $saved += (int) $this->saveChangeRiskIfAbsent(
                $log,
                'manual_allowance_change',
                "Manual allowance change to '{$log->field_changed}' on run {$run->ref_number}.",
                'medium',
            );
        }

        // Manual deduction removals
        foreach ($this->detectManualDeductionRemoval($run) as $change) {
            $log = PayrollChangeLog::find($change['id'] ?? null);
            if (!$log) continue;
            $saved += (int) $this->saveChangeRiskIfAbsent(
                $log,
                'manual_deduction_removal',
                "Deduction '{$log->field_changed}' manually reduced on run {$run->ref_number}.",
                'high',
            );
        }

        // Bank/account changes for any employee on this run
        $bankChanges = DB::table('payroll_change_logs')
            ->where('run_id', $run->id)
            ->where(function ($q) {
                $q->where('field_changed', 'like', '%bank%')
                  ->orWhere('field_changed', 'like', '%account%');
            })
            ->get();

        foreach ($bankChanges as $change) {
            $log = PayrollChangeLog::find($change->id);
            if (!$log) continue;
            $saved += (int) $this->saveChangeRiskIfAbsent(
                $log,
                'bank_change_before_payroll',
                "Bank/account detail changed on run {$run->ref_number} for employee #{$log->employee_id}.",
                'critical',
            );
        }

        // Any remaining high-risk logs without a risk record
        $orphans = DB::table('payroll_change_logs')
            ->where('run_id', $run->id)
            ->where('risk_score', '>=', self::HIGH_RISK_THRESHOLD)
            ->whereNotExists(function ($q) {
                $q->from('payroll_change_risks')
                  ->whereColumn('payroll_change_risks.change_log_id', 'payroll_change_logs.id');
            })
            ->get();

        foreach ($orphans as $change) {
            $log = PayrollChangeLog::find($change->id);
            if (!$log) continue;
            $level = $this->deriveRiskLevel((int) $log->risk_score);
            $saved += (int) $this->saveChangeRiskIfAbsent(
                $log,
                'high_risk_field_edit',
                "High-risk field '{$log->field_changed}' edited (score {$log->risk_score}) on run {$run->ref_number}.",
                $level === 'critical' ? 'critical' : 'high',
            );
        }

        return $saved;
    }

    public function getChangeLogForRun(PayrollRun $run): \Illuminate\Support\Collection
    {
        return DB::table('payroll_change_logs as pcl')
            ->leftJoin('payroll_change_risks as pcr', 'pcr.change_log_id', '=', 'pcl.id')
            ->leftJoin('payroll_runs as pr', 'pr.id', '=', 'pcl.run_id')
            ->where('pcl.run_id', $run->id)
            ->select(
                'pcl.id', 'pcl.employee_id', 'pcl.field_changed',
                'pcl.old_value', 'pcl.new_value', 'pcl.change_type',
                'pcl.risk_score', 'pcl.risk_level', 'pcl.changed_at', 'pcl.change_reason',
                'pcl.run_id',
                'pr.ref_number as run_ref',
                'pcr.id as risk_id', 'pcr.risk_type', 'pcr.severity', 'pcr.status as risk_status',
                'pcr.risk_description',
            )
            ->orderByDesc('pcl.changed_at')
            ->get();
    }

    public function getStats(PayrollRun $run): array
    {
        $total   = DB::table('payroll_change_logs')->where('run_id', $run->id)->count();
        $manual  = DB::table('payroll_change_logs')->where('run_id', $run->id)->where('change_type', 'manual')->count();
        $flagged = DB::table('payroll_change_risks as pcr')
            ->join('payroll_change_logs as pcl', 'pcl.id', '=', 'pcr.change_log_id')
            ->where('pcl.run_id', $run->id)
            ->where('pcr.status', 'flagged')
            ->count();
        $critical = DB::table('payroll_change_risks as pcr')
            ->join('payroll_change_logs as pcl', 'pcl.id', '=', 'pcr.change_log_id')
            ->where('pcl.run_id', $run->id)
            ->where('pcr.severity', 'critical')
            ->count();

        return compact('total', 'manual', 'flagged', 'critical');
    }

    public function saveChangeRisk(
        PayrollChangeLog $log,
        string $riskType,
        string $description,
        string $severity,
    ): PayrollChangeRisk {
        return PayrollChangeRisk::firstOrCreate(
            ['change_log_id' => $log->id, 'risk_type' => $riskType],
            [
                'risk_description' => $description,
                'severity'         => $severity,
                'flagged_by'       => 'system',
                'status'           => 'flagged',
            ],
        );
    }

    private function saveChangeRiskIfAbsent(
        PayrollChangeLog $log,
        string $riskType,
        string $description,
        string $severity,
    ): bool {
        $exists = DB::table('payroll_change_risks')
            ->where('change_log_id', $log->id)
            ->where('risk_type', $riskType)
            ->exists();

        if ($exists) return false;

        $this->saveChangeRisk($log, $riskType, $description, $severity);
        return true;
    }

    private function deriveRiskLevel(int $score): string
    {
        return match (true) {
            $score >= 90 => 'critical',
            $score >= 70 => 'high',
            $score >= 50 => 'medium',
            $score >= 30 => 'low',
            default      => 'none',
        };
    }
}
