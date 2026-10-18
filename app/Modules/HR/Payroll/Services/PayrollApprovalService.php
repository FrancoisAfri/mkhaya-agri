<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use App\Modules\HR\Payroll\Models\PayrollApproval;
use App\Modules\HR\Payroll\Models\PayrollApprovalComment;
use App\Modules\HR\Payroll\Models\PayrollApprovalStep;
use App\Modules\HR\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollApprovalService
{
    // Ordered approval chain
    private const LEVELS = ['payroll_officer', 'hr_manager', 'finance', 'executive'];

    // Critical anomalies block release; if >3 critical → escalate straight to executive
    private const AUTO_ESCALATE_CRITICAL_THRESHOLD = 3;

    // ── Submission ────────────────────────────────────────────────────────────

    public function submitPayrollForReview(PayrollRun $run): PayrollApproval
    {
        // Prevent duplicate open approvals
        $existing = PayrollApproval::where('run_id', $run->id)
            ->whereIn('status', ['pending', 'escalated'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $block = $this->blockPayrollRelease($run);

        // Check if high-risk (critical anomalies present)
        $criticalCount = PayrollAnomaly::where('run_id', $run->id)
            ->where('severity', 'critical')
            ->where('status', 'open')
            ->count();

        $isHighRisk    = $criticalCount > 0;
        $startingLevel = $criticalCount >= self::AUTO_ESCALATE_CRITICAL_THRESHOLD
            ? 'executive'
            : 'payroll_officer';

        $approval = PayrollApproval::create([
            'run_id'         => $run->id,
            'current_level'  => $startingLevel,
            'status'         => 'pending',
            'submitted_by'   => auth()->id(),
            'due_date'       => now()->addDays(3)->toDateString(),
            'high_risk'      => $isHighRisk,
            'auto_escalated' => $startingLevel === 'executive' && $criticalCount >= self::AUTO_ESCALATE_CRITICAL_THRESHOLD,
            'metadata'       => [
                'critical_anomalies'   => $criticalCount,
                'block_reasons'        => $block['reasons'],
                'submitted_from_status'=> $run->status?->value ?? $run->status,
            ],
        ]);

        // If a configurable approval chain is defined, start it at its first step.
        $firstStep = \App\Modules\HR\Payroll\Models\PayrollApprovalConfig::where('is_active', true)->min('step_number');
        if ($firstStep !== null) {
            $approval->update(['current_step' => (int) $firstStep]);
        }

        // Advance run to Review status
        $run->update(['status' => PayrollRunStatus::Review->value]);

        return $approval;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function approvePayrollRun(PayrollApproval $approval, ?string $comments = null): bool
    {
        if (!in_array($approval->status, ['pending', 'escalated'])) {
            return false;
        }

        // Configurable chain: enforce that the approver matches THIS step's role/employee,
        // then advance to the next configured step (or finalise past the last one).
        if ($approval->current_step !== null) {
            $step = \App\Modules\HR\Payroll\Models\PayrollApprovalConfig::where('is_active', true)
                ->where('step_number', $approval->current_step)->first();

            if ($step && ! $this->userMayApproveStep($step)) {
                return false; // not authorised for this step
            }

            PayrollApprovalStep::create([
                'approval_id' => $approval->id,
                'approver_id' => auth()->id(),
                'level'       => $step?->name ?? ('Step ' . $approval->current_step),
                'action'      => 'approved',
                'comments'    => $comments,
                'acted_at'    => now(),
            ]);

            $nextStep = (int) \App\Modules\HR\Payroll\Models\PayrollApprovalConfig::where('is_active', true)
                ->where('step_number', '>', $approval->current_step)->min('step_number');

            if ($nextStep) {
                $approval->update(['current_step' => $nextStep, 'status' => 'pending']);
            } else {
                $approval->update(['status' => 'approved']); // all steps done — releasable
            }

            return true;
        }

        // Legacy level-based chain (no configured steps).
        PayrollApprovalStep::create([
            'approval_id' => $approval->id,
            'approver_id' => auth()->id(),
            'level'       => $approval->current_level,
            'action'      => 'approved',
            'comments'    => $comments,
            'acted_at'    => now(),
        ]);

        $nextLevel = $this->nextLevel($approval->current_level);

        if ($nextLevel) {
            $approval->update(['current_level' => $nextLevel, 'status' => 'pending']);
        } else {
            $approval->update(['status' => 'approved']);
        }

        return true;
    }

    /** True when the signed-in user satisfies a configured approval step (role or specific employee). */
    private function userMayApproveStep(\App\Modules\HR\Payroll\Models\PayrollApprovalConfig $step): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        // Platform/master admins can always approve.
        if (($user->is_platform_admin ?? false) || ($user->is_master_admin ?? false)) {
            return true;
        }
        if ($step->approver_type === 'employee') {
            return (int) $step->employee_id === (int) $user->id;
        }
        return $step->role_name ? $user->hasRole($step->role_name) : true;
    }

    public function rejectPayrollRun(PayrollApproval $approval, string $reason): bool
    {
        if (!in_array($approval->status, ['pending', 'escalated'])) {
            return false;
        }

        PayrollApprovalStep::create([
            'approval_id' => $approval->id,
            'approver_id' => auth()->id(),
            'level'       => $approval->current_level,
            'action'      => 'rejected',
            'comments'    => $reason,
            'acted_at'    => now(),
        ]);

        $approval->update(['status' => 'rejected']);

        // Revert run to draft
        $approval->payrollRun?->update(['status' => PayrollRunStatus::Draft->value]);

        return true;
    }

    public function requestPayrollCorrection(PayrollApproval $approval, string $notes): bool
    {
        if (!in_array($approval->status, ['pending', 'escalated'])) {
            return false;
        }

        PayrollApprovalStep::create([
            'approval_id' => $approval->id,
            'approver_id' => auth()->id(),
            'level'       => $approval->current_level,
            'action'      => 'info_requested',
            'comments'    => $notes,
            'acted_at'    => now(),
        ]);

        $approval->update(['status' => 'info_requested']);

        // Return run to draft so corrections can be applied
        $approval->payrollRun?->update(['status' => PayrollRunStatus::Draft->value]);

        return true;
    }

    public function escalateHighRiskPayroll(PayrollApproval $approval, string $reason, bool $auto = false): bool
    {
        if (!in_array($approval->status, ['pending', 'escalated', 'info_requested'])) {
            return false;
        }

        PayrollApprovalStep::create([
            'approval_id' => $approval->id,
            'approver_id' => $auto ? (auth()->id() ?? 0) : auth()->id(),
            'level'       => $approval->current_level,
            'action'      => 'escalated',
            'comments'    => $reason,
            'acted_at'    => now(),
        ]);

        $approval->update([
            'current_level'  => 'executive',
            'status'         => 'escalated',
            'auto_escalated' => $auto,
        ]);

        return true;
    }

    public function addComment(PayrollApproval $approval, string $comment, bool $isInternal = false): void
    {
        PayrollApprovalComment::create([
            'approval_id' => $approval->id,
            'user_id'     => auth()->id(),
            'comment'     => $comment,
            'is_internal' => $isInternal,
            'created_at'  => now(),
        ]);
    }

    // ── Gate check ────────────────────────────────────────────────────────────

    public function blockPayrollRelease(PayrollRun $run): array
    {
        $reasons = [];

        $criticalOpen = PayrollAnomaly::where('run_id', $run->id)
            ->where('severity', 'critical')
            ->where('status', 'open')
            ->count();

        if ($criticalOpen > 0) {
            $reasons[] = "{$criticalOpen} critical anomaly/anomalies are open and must be resolved before release.";
        }

        $openApproval = PayrollApproval::where('run_id', $run->id)
            ->whereIn('status', ['pending', 'escalated'])
            ->exists();

        if (!$openApproval) {
            $hasApproved = PayrollApproval::where('run_id', $run->id)
                ->where('status', 'approved')
                ->exists();

            if (!$hasApproved && $criticalOpen > 0) {
                $reasons[] = 'No approved payroll approval record exists for this run.';
            }
        }

        return [
            'blocked' => count($reasons) > 0,
            'reasons' => $reasons,
        ];
    }

    // ── Auto-escalation (called on submit or scheduled job) ───────────────────

    public function autoEscalateHighRiskPayrolls(): int
    {
        $escalated = 0;

        try {
            $pending = PayrollApproval::where('status', 'pending')
                ->where('current_level', '!=', 'executive')
                ->where('high_risk', true)
                ->get();

            foreach ($pending as $approval) {
                if ($this->escalateHighRiskPayroll($approval, 'Auto-escalated: payroll run flagged as high-risk due to critical anomalies.', true)) {
                    $escalated++;
                }
            }
        } catch (\Throwable $e) {
            Log::error('autoEscalateHighRiskPayrolls error: ' . $e->getMessage());
        }

        return $escalated;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function nextLevel(string $currentLevel): ?string
    {
        $idx = array_search($currentLevel, self::LEVELS, true);

        return ($idx !== false && isset(self::LEVELS[$idx + 1]))
            ? self::LEVELS[$idx + 1]
            : null;
    }

    public function getLevels(): array
    {
        return self::LEVELS;
    }
}
