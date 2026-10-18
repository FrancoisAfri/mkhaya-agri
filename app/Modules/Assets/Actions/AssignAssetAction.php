<?php

namespace App\Modules\Assets\Actions;

use App\Core\User\Models\User;
use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Notifications\AssetAssignedNotification;
use Illuminate\Support\Facades\DB;

class AssignAssetAction
{
    public function execute(array $data, int $requestedBy): AssetAssignment|AssetApproval
    {
        $result = DB::transaction(function () use ($data, $requestedBy) {
            $asset     = Assets::findOrFail($data['asset_id']);
            $threshold = (float) AssetSetting::get('approval_threshold_l1', 0);
            $cost      = (float) ($asset->purchase_cost ?? 0);

            $skipApproval = $data['_skip_approval'] ?? false;
            unset($data['_skip_approval']);

            if (! $skipApproval && $threshold > 0 && $cost >= $threshold) {
                return $this->createApproval($data, $requestedBy, $asset);
            }

            return $this->createAssignment($data, $requestedBy, $asset);
        });

        // Notify outside the transaction so a mail failure cannot roll back the assignment
        if ($result instanceof AssetAssignment) {
            try {
                $assignee = User::find($result->assigned_to);
                $assignee?->notify(new AssetAssignedNotification($result->loadMissing('asset')));
            } catch (\Throwable) {
                // Notification failure must never block the assignment
            }
        }

        return $result;
    }

    private function createAssignment(array $data, int $requestedBy, Assets $asset, ?int $approvalId = null): AssetAssignment
    {
        // Close any existing active assignment before creating the new one —
        // stamping returned_at and writing a `returned` custody log for the
        // outgoing holder so the ledger isn't left showing a handover with no
        // record that the previous holder ever released the asset.
        $previousAssignments = AssetAssignment::where('asset_id', $asset->id)
            ->where('is_current', true)
            ->lockForUpdate()
            ->get();

        foreach ($previousAssignments as $previous) {
            $previous->update(['is_current' => false, 'returned_at' => now()]);

            AssetCustodyLog::create([
                'asset_id'      => $asset->id,
                'assignment_id' => $previous->id,
                'action'        => 'returned',
                'from_user_id'  => $previous->assigned_to,
                'performed_by'  => $requestedBy,
                'notes'         => 'Auto-closed on reassignment.',
                'occurred_at'   => now(),
            ]);
        }

        $assignment = AssetAssignment::create([
            'asset_id'        => $asset->id,
            'assigned_to'     => $data['assigned_to'],
            'assigned_by'     => $requestedBy,
            'location_id'     => $data['location_id']    ?? null,
            'assignment_type' => $data['assignment_type'] ?? 'permanent',
            'effective_date'  => $data['effective_date']  ?? now()->toDateString(),
            'return_due_date' => $data['end_date'] ?? null,
            'is_current'      => true,
            'approval_id'     => $approvalId,
            'notes'           => $data['notes']           ?? null,
        ]);

        $asset->update(['asset_status' => 'active', 'user_id' => $data['assigned_to']]);

        AssetCustodyLog::create([
            'asset_id'       => $asset->id,
            'assignment_id'  => $assignment->id,
            'action'         => 'assigned',
            'to_user_id'     => $data['assigned_to'],
            'to_location_id' => $data['location_id'] ?? null,
            'performed_by'   => $requestedBy,
            'notes'          => $data['notes'] ?? null,
            'occurred_at'    => now(),
        ]);

        return $assignment;
    }

    private function createApproval(array $data, int $requestedBy, Assets $asset): AssetApproval
    {
        $approvers    = AssetSetting::get('approval_approvers_assignment', []);
        $totalSteps   = max(1, count($approvers));

        $approval = AssetApproval::create([
            'type'          => ApprovalType::Assignment,
            'approvable_type' => AssetAssignment::class,
            'approvable_id'   => 0,
            'requested_by'  => $requestedBy,
            'current_step'  => 1,
            'total_steps'   => $totalSteps,
            'status'        => ApprovalStatus::Pending,
            'notes'         => $data['notes'] ?? null,
        ]);

        foreach (array_values($approvers) as $i => $approverId) {
            AssetApprovalStep::create([
                'approval_id'  => $approval->id,
                'step_number'  => $i + 1,
                'approver_id'  => $approverId,
                'status'       => ApprovalStatus::Pending,
            ]);
        }

        // Store assignment intent in the approval notes so it can be created on final approval
        $approval->update(['notes' => json_encode($data)]);

        return $approval;
    }
}
