<?php

namespace App\Modules\ContractManagement\Services;

use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractApprovalStep;
use App\Modules\ContractManagement\Models\ContractStepStatus;
use Illuminate\Support\Collection;

class ContractApprovalService
{
    /**
     * Contracts currently awaiting an approval decision.
     */
    public function pending(): Collection
    {
        return Contract::query()
            ->with(['type', 'category'])
            ->whereIn('contract_status', ['published', 'pending'])
            ->where('approval_status', 'pending')
            ->latest()
            ->get();
    }

    /**
     * Approve a contract at a given step. Records the decision, then either
     * advances current_step_id to the next step or finalises to 'active'.
     */
    public function approve(Contract $contract, ?ContractApprovalStep $step, ?int $userId = null, ?string $comment = null): Contract
    {
        if ($step) {
            ContractStepStatus::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'step_id' => $step->id,
                    'step_type' => $step->parent_step_id ? 'child' : 'parent',
                ],
                [
                    'status' => 'approved',
                    'comment' => $comment,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]
            );
        }

        $next = $this->nextStep($step);

        if ($next) {
            $contract->update([
                'current_step_id' => $next->id,
                'contract_status' => 'pending',
                'approval_status' => 'pending',
            ]);
        } else {
            // No further steps: finalise.
            $contract->update([
                'current_step_id' => null,
                'contract_status' => 'active',
                'approval_status' => 'approved',
            ]);
        }

        return $contract->refresh();
    }

    /**
     * Decline a contract at a given step.
     */
    public function decline(Contract $contract, ?ContractApprovalStep $step, ?int $userId = null, ?string $comment = null): Contract
    {
        if ($step) {
            ContractStepStatus::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'step_id' => $step->id,
                    'step_type' => $step->parent_step_id ? 'child' : 'parent',
                ],
                [
                    'status' => 'declined',
                    'comment' => $comment,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]
            );
        }

        $contract->update([
            'contract_status' => 'cancelled',
            'approval_status' => 'rejected',
            'rejection_reason' => $comment,
        ]);

        return $contract->refresh();
    }

    private function nextStep(?ContractApprovalStep $step): ?ContractApprovalStep
    {
        if (! $step) {
            return null;
        }

        return ContractApprovalStep::query()
            ->whereNull('parent_step_id')
            ->where('status', 1)
            ->where('step_number', '>', $step->step_number)
            ->orderBy('step_number')
            ->first();
    }
}
