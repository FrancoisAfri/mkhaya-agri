<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\PayrollCorrection;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollCorrectionService
{
    private const PAYE_THRESHOLD         = 7_979.17;
    private const ALLOWANCE_SPIKE_FACTOR = 1.50;  // >50% above 6-month avg
    private const TAX_TOLERANCE          = 0.10;  // 10% variance before flagging

    public function __construct(
        protected PayrollChangeMonitorService $changeMonitorService,
    ) {}

    // ── Detection ─────────────────────────────────────────────────────────────

    public function suggestDuplicatePaymentCorrections(PayrollRun $run): array
    {
        try {
            $duplicates = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->select('employee_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(net_pay) as total_net'))
                ->groupBy('employee_id')
                ->having('cnt', '>', 1)
                ->get();

            return $duplicates->map(fn ($row) => [
                'employee_id'      => $row->employee_id,
                'payslip_id'       => null,
                'correction_type'  => 'duplicate_payment',
                'description'      => "Employee #{$row->employee_id} has {$row->cnt} payslips in run {$run->ref_number} (total net R" . number_format((float) $row->total_net, 2) . "). Likely duplicate entry.",
                'suggested_action' => "Remove duplicate payslip(s) and retain only the most recently calculated record for this employee in run {$run->ref_number}.",
                'field_affected'   => 'payslip_count',
                'original_value'   => (float) $row->cnt,
                'corrected_value'  => 1.0,
                'metadata'         => ['payslip_count' => (int) $row->cnt, 'total_net' => (float) $row->total_net],
            ])->toArray();
        } catch (\Throwable $e) {
            Log::error('suggestDuplicatePaymentCorrections error: ' . $e->getMessage());
            return [];
        }
    }

    public function suggestAllowanceCorrections(PayrollRun $run): array
    {
        try {
            $since = $run->period_start->subMonths(6);

            $historical = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->join('payroll_runs as pr', 'pr.id', '=', 'ps.payroll_run_id')
                ->where('pr.status', 'released')
                ->where('pr.period_start', '>=', $since)
                ->where('pr.period_start', '<', $run->period_start)
                ->where('pel.line_type', 'allowance')
                ->select('ps.employee_id', 'pel.line_description', DB::raw('AVG(pel.amount) as avg_amount'))
                ->groupBy('ps.employee_id', 'pel.line_description')
                ->get()
                ->keyBy(fn ($r) => $r->employee_id . '::' . $r->line_description);

            $current = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'allowance')
                ->select('ps.employee_id', 'ps.id as payslip_id', 'pel.line_description', 'pel.amount')
                ->get();

            $suggestions = [];
            foreach ($current as $row) {
                $hist = $historical[$row->employee_id . '::' . $row->line_description] ?? null;
                if (!$hist || (float) $hist->avg_amount <= 0) continue;

                $curr  = (float) $row->amount;
                $avg   = (float) $hist->avg_amount;
                $ratio = $curr / $avg;
                if ($ratio <= self::ALLOWANCE_SPIKE_FACTOR) continue;

                $suggestions[] = [
                    'employee_id'      => $row->employee_id,
                    'payslip_id'       => $row->payslip_id,
                    'correction_type'  => 'allowance_error',
                    'description'      => "Employee #{$row->employee_id} allowance '{$row->line_description}' is R" . number_format($curr, 2) . " — " . round(($ratio - 1) * 100) . "% above the 6-month average of R" . number_format($avg, 2) . ".",
                    'suggested_action' => "Review and confirm this allowance. If incorrect, correct to the 6-month average of R" . number_format($avg, 2) . ".",
                    'field_affected'   => $row->line_description,
                    'original_value'   => $curr,
                    'corrected_value'  => round($avg, 2),
                    'metadata'         => ['line_description' => $row->line_description, 'historical_avg' => round($avg, 2), 'ratio' => round($ratio, 2)],
                ];
            }

            return $suggestions;
        } catch (\Throwable $e) {
            Log::error('suggestAllowanceCorrections error: ' . $e->getMessage());
            return [];
        }
    }

    public function suggestLeaveCorrections(PayrollRun $run): array
    {
        try {
            $leaveDeductions = DB::table('payroll_earnings_lines as pel')
                ->join('payslips as ps', 'ps.id', '=', 'pel.payslip_id')
                ->where('ps.payroll_run_id', $run->id)
                ->where('pel.line_type', 'deduction')
                ->where(fn ($q) => $q->where('pel.line_description', 'like', '%leave%')
                    ->orWhere('pel.line_description', 'like', '%unpaid%'))
                ->select('ps.employee_id', 'ps.id as payslip_id', 'pel.amount', 'pel.line_description')
                ->get();

            $employeeIds = $leaveDeductions->pluck('employee_id')->unique();
            $hrPeopleMap = DB::table('hr_people')
                ->whereIn('user_id', $employeeIds)
                ->select('id', 'user_id')
                ->get()->keyBy('user_id');

            $suggestions = [];
            foreach ($leaveDeductions as $row) {
                $hpId = $hrPeopleMap[$row->employee_id]->id ?? null;
                if (!$hpId) continue;

                $hasLeave = DB::table('leave_requests')
                    ->where('employee_id', $hpId)
                    ->where('status', 'approved')
                    ->where('start_date', '<=', $run->period_end)
                    ->where('end_date', '>=', $run->period_start)
                    ->exists();

                if ($hasLeave) continue;

                $suggestions[] = [
                    'employee_id'      => $row->employee_id,
                    'payslip_id'       => $row->payslip_id,
                    'correction_type'  => 'leave_error',
                    'description'      => "Employee #{$row->employee_id} has a '{$row->line_description}' deduction of R" . number_format((float) $row->amount, 2) . " but no approved leave falls within the pay period.",
                    'suggested_action' => "Verify leave records. If no leave was taken, remove the deduction and recalculate the payslip.",
                    'field_affected'   => $row->line_description,
                    'original_value'   => (float) $row->amount,
                    'corrected_value'  => 0.0,
                    'metadata'         => ['line_description' => $row->line_description, 'period_start' => $run->period_start->toDateString(), 'period_end' => $run->period_end->toDateString()],
                ];
            }

            return $suggestions;
        } catch (\Throwable $e) {
            Log::error('suggestLeaveCorrections error: ' . $e->getMessage());
            return [];
        }
    }

    public function suggestTaxCorrections(PayrollRun $run): array
    {
        try {
            $rows = DB::table('payslips')
                ->where('payroll_run_id', $run->id)
                ->where('gross_pay', '>', self::PAYE_THRESHOLD)
                ->select('id', 'employee_id', 'gross_pay', 'paye')
                ->get();

            $suggestions = [];
            foreach ($rows as $row) {
                $gross          = (float) $row->gross_pay;
                $paye           = (float) $row->paye;
                $estimatedPaye  = round(max(0, $gross - self::PAYE_THRESHOLD) * 0.18, 2);

                if ($estimatedPaye <= 0) continue;

                $diff = abs($paye - $estimatedPaye) / $estimatedPaye;
                if ($diff < self::TAX_TOLERANCE) continue;

                if ($paye <= 0) {
                    $desc   = "Employee #{$row->employee_id} gross pay R" . number_format($gross, 2) . " exceeds the PAYE threshold but no PAYE was deducted.";
                    $action = "Apply estimated PAYE of R" . number_format($estimatedPaye, 2) . " (18% marginal rate). Verify against any tax directive on file.";
                } else {
                    $dir    = $paye < $estimatedPaye ? 'under-deducted' : 'over-deducted';
                    $desc   = "Employee #{$row->employee_id} PAYE R" . number_format($paye, 2) . " is {$dir} (estimated R" . number_format($estimatedPaye, 2) . ", " . round($diff * 100, 1) . "% variance).";
                    $action = "Recalculate PAYE using SARS tax tables. Adjust to R" . number_format($estimatedPaye, 2) . " unless a tax directive applies.";
                }

                $suggestions[] = [
                    'employee_id'      => $row->employee_id,
                    'payslip_id'       => $row->id,
                    'correction_type'  => 'tax_error',
                    'description'      => $desc,
                    'suggested_action' => $action,
                    'field_affected'   => 'paye',
                    'original_value'   => $paye,
                    'corrected_value'  => $estimatedPaye,
                    'metadata'         => ['gross_pay' => $gross, 'current_paye' => $paye, 'estimated_paye' => $estimatedPaye, 'variance_pct' => round($diff * 100, 1)],
                ];
            }

            return $suggestions;
        } catch (\Throwable $e) {
            Log::error('suggestTaxCorrections error: ' . $e->getMessage());
            return [];
        }
    }

    // ── Orchestration & persistence ───────────────────────────────────────────

    public function suggestPayrollCorrections(PayrollRun $run): int
    {
        $all = array_merge(
            $this->suggestDuplicatePaymentCorrections($run),
            $this->suggestAllowanceCorrections($run),
            $this->suggestLeaveCorrections($run),
            $this->suggestTaxCorrections($run),
        );

        $saved = 0;
        foreach ($all as $s) {
            $correction = PayrollCorrection::firstOrCreate(
                [
                    'run_id'          => $run->id,
                    'employee_id'     => $s['employee_id'],
                    'correction_type' => $s['correction_type'],
                    'field_affected'  => $s['field_affected'] ?? null,
                ],
                [
                    'payslip_id'       => $s['payslip_id'] ?? null,
                    'description'      => $s['description'],
                    'suggested_action' => $s['suggested_action'],
                    'original_value'   => $s['original_value'] ?? null,
                    'corrected_value'  => $s['corrected_value'] ?? null,
                    'status'           => 'suggested',
                    'suggested_by'     => 'system',
                    'metadata'         => $s['metadata'] ?? null,
                ],
            );

            if ($correction->wasRecentlyCreated) {
                $saved++;
            }
        }

        return $saved;
    }

    // ── Approval & application ────────────────────────────────────────────────

    public function approveSuggestedCorrection(PayrollCorrection $correction): bool
    {
        if ($correction->status !== 'suggested') {
            return false;
        }

        $correction->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return true;
    }

    public function rejectSuggestedCorrection(PayrollCorrection $correction, string $reason): bool
    {
        if ($correction->status !== 'suggested') {
            return false;
        }

        $correction->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'approved_by'      => auth()->id(),
            'approved_at'      => now(),
        ]);

        return true;
    }

    public function applyPayrollCorrection(PayrollCorrection $correction): bool
    {
        if ($correction->status !== 'approved') {
            return false;
        }

        $allowedFields = ['paye', 'uif', 'pension', 'basic_salary', 'gross_pay', 'net_pay', 'total_deductions'];

        if (
            $correction->payslip_id
            && $correction->field_affected
            && $correction->corrected_value !== null
            && in_array($correction->field_affected, $allowedFields, true)
        ) {
            $old = DB::table('payslips')->where('id', $correction->payslip_id)->value($correction->field_affected);

            DB::table('payslips')
                ->where('id', $correction->payslip_id)
                ->update([$correction->field_affected => $correction->corrected_value]);

            $this->changeMonitorService->logPayrollChange([
                'run_id'        => $correction->run_id,
                'employee_id'   => $correction->employee_id,
                'payslip_id'    => $correction->payslip_id,
                'field_changed' => $correction->field_affected,
                'old_value'     => (string) $old,
                'new_value'     => (string) $correction->corrected_value,
                'change_type'   => 'correction',
                'changed_by'    => auth()->id(),
                'changed_at'    => now(),
            ]);
        }

        $correction->update([
            'status'     => 'applied',
            'applied_by' => auth()->id(),
            'applied_at' => now(),
        ]);

        return true;
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function getSummary(PayrollRun $run): object
    {
        $base = PayrollCorrection::where('run_id', $run->id);

        return (object) [
            'total_suggested' => (clone $base)->where('status', 'suggested')->count(),
            'total_approved'  => (clone $base)->where('status', 'approved')->count(),
            'total_applied'   => (clone $base)->where('status', 'applied')->count(),
            'total_rejected'  => (clone $base)->where('status', 'rejected')->count(),
        ];
    }
}
