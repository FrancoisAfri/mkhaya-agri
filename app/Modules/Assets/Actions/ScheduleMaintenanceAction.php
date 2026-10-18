<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\ApprovalStatus;
use App\Modules\Assets\Enums\ApprovalType;
use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Models\AssetApproval;
use App\Modules\Assets\Models\AssetApprovalStep;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class ScheduleMaintenanceAction
{
    public function execute(array $data, int $requestedBy): AssetMaintenanceSchedule|AssetApproval
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            $asset     = Assets::findOrFail($data['asset_id']);
            $threshold = (float) AssetSetting::get('approval_threshold_l1', 0);
            $cost      = (float) ($data['estimated_cost'] ?? 0);

            $needsApproval = ! ($data['_skip_approval'] ?? false)
                && $threshold > 0
                && $cost >= $threshold;

            unset($data['_skip_approval']);

            if ($needsApproval) {
                return $this->createApproval($data, $requestedBy);
            }

            return $this->createSchedule($data, $requestedBy);
        });
    }

    public function createSchedule(array $data, int $requestedBy, ?int $approvalId = null): AssetMaintenanceSchedule
    {
        return AssetMaintenanceSchedule::create([
            'asset_id'         => $data['asset_id'],
            'title'            => $data['title'],
            'description'      => $data['description']     ?? null,
            'maintenance_type' => $data['maintenance_type'] ?? 'preventive',
            'status'           => MaintenanceStatus::Scheduled,
            'assigned_to'      => $data['assigned_to']     ?? null,
            'vendor_name'      => $data['vendor_name']     ?? null,
            'estimated_cost'   => $data['estimated_cost']  ?? null,
            'due_date'         => $data['due_date'],
            'recurrence_days'  => $data['recurrence_days'] ?? null,
            'approval_id'      => $approvalId,
            'notes'            => $data['notes']           ?? null,
        ]);
    }

    private function createApproval(array $data, int $requestedBy): AssetApproval
    {
        $approvers  = AssetSetting::get('approval_approvers_maintenance', []);
        $totalSteps = max(1, count($approvers));

        $approval = AssetApproval::create([
            'type'            => ApprovalType::Maintenance,
            'approvable_type' => AssetMaintenanceSchedule::class,
            'approvable_id'   => 0,
            'requested_by'    => $requestedBy,
            'current_step'    => 1,
            'total_steps'     => $totalSteps,
            'status'          => ApprovalStatus::Pending,
            'notes'           => json_encode($data),
        ]);

        foreach (array_values($approvers) as $i => $approverId) {
            AssetApprovalStep::create([
                'approval_id' => $approval->id,
                'step_number' => $i + 1,
                'approver_id' => $approverId,
                'status'      => ApprovalStatus::Pending,
            ]);
        }

        return $approval;
    }
}
