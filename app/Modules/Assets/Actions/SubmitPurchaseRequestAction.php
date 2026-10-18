<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\AssetPurchaseRequest;
use App\Modules\Assets\Models\AssetSetting;
use Illuminate\Support\Facades\DB;

class SubmitPurchaseRequestAction
{
    public function execute(AssetPurchaseRequest $request, int $submittedBy): AssetPurchaseRequest
    {
        abort_unless($request->status->value === 'draft', 422, 'Only draft requests can be submitted.');

        $approvers = array_values(array_filter(
            (array) AssetSetting::get('approval_approvers_procurement', [])
        ));

        return DB::transaction(function () use ($request, $submittedBy, $approvers) {
            // No approval chain configured → submission is auto-approved so the
            // downstream pipeline (deliver → create assets) can proceed instead of
            // dead-ending at "submitted" with no step anyone can action.
            if (empty($approvers)) {
                $request->update([
                    'status'       => 'approved',
                    'submitted_at' => now(),
                ]);

                return $request->refresh();
            }

            $approval = AssetApproval::create([
                'type'            => ApprovalType::Procurement,
                'approvable_type' => AssetPurchaseRequest::class,
                'approvable_id'   => $request->id,
                'requested_by'    => $submittedBy,
                'current_step'    => 1,
                'total_steps'     => count($approvers),
                'status'          => ApprovalStatus::Pending,
            ]);

            foreach ($approvers as $i => $approverId) {
                AssetApprovalStep::create([
                    'approval_id' => $approval->id,
                    'step_number' => $i + 1,
                    'approver_id' => (int) $approverId,
                    'status'      => ApprovalStatus::Pending,
                ]);
            }

            $request->update([
                'status'       => 'submitted',
                'submitted_at' => now(),
                'approval_id'  => $approval->id,
            ]);

            return $request->refresh();
        });
    }
}
