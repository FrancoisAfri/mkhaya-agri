<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class ApproveAssetApprovalAction
{
    public function execute(AssetApproval $approval, int $approverId, bool $approved, ?string $notes = null): AssetApproval
    {
        return DB::transaction(function () use ($approval, $approverId, $approved, $notes) {
            $this->guardAgainstRequestor($approval, $approverId);

            $step = $approval->steps()
                ->where('step_number', $approval->current_step)
                ->where('approver_id', $approverId)
                ->firstOrFail();

            $step->update([
                'status'     => $approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                'decided_at' => now(),
                'notes'      => $notes,
            ]);

            if (! $approved) {
                $approval->update(['status' => ApprovalStatus::Rejected, 'completed_at' => now()]);
                return $approval->fresh();
            }

            $isLastStep = $approval->current_step >= $approval->total_steps;

            if ($isLastStep) {
                $approval->update([
                    'status'       => ApprovalStatus::Approved,
                    'completed_at' => now(),
                ]);
                $this->activateApprovedRecord($approval);
            } else {
                $approval->increment('current_step');
            }

            return $approval->fresh(['steps', 'comments']);
        });
    }

    private function guardAgainstRequestor(AssetApproval $approval, int $approverId): void
    {
        // SOX segregation of duties: requestor cannot be their own approver
        if ($approval->requested_by === $approverId) {
            throw new \DomainException('The approver cannot be the same person who raised the approval request.');
        }
    }

    private function activateApprovedRecord(AssetApproval $approval): void
    {
        match($approval->type) {
            ApprovalType::Assignment  => $this->activateAssignment($approval),
            ApprovalType::Procurement => $this->activateProcurement($approval),
            default                   => null,
        };
    }

    private function activateProcurement(AssetApproval $approval): void
    {
        $purchaseRequest = \App\Modules\Assets\Models\AssetPurchaseRequest::find($approval->approvable_id);

        if ($purchaseRequest && $purchaseRequest->status->value === 'submitted') {
            $purchaseRequest->update(['status' => 'approved']);
        }
    }

    private function activateAssignment(AssetApproval $approval): void
    {
        $data  = json_decode($approval->notes, true);
        $asset = Assets::findOrFail($data['asset_id']);

        $action     = new AssignAssetAction();
        $assignment = $action->execute(array_merge($data, ['_skip_approval' => true]), $approval->requested_by);

        // Back-fill the approval_id on the created assignment
        if ($assignment instanceof AssetAssignment) {
            $assignment->update(['approval_id' => $approval->id]);
            $approval->update(['approvable_id' => $assignment->id]);
        }
    }
}
