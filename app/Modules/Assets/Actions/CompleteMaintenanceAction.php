<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Enums\MaintenanceStatus;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetMaintenanceLog;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetRiskProfile;
use Illuminate\Support\Facades\DB;

class CompleteMaintenanceAction
{
    public function execute(AssetMaintenanceSchedule $schedule, array $data, int $performedBy): AssetMaintenanceLog
    {
        // Idempotency: a double-submit would otherwise log the work twice and spawn
        // a second recurring schedule + custody log on each replay.
        abort_if($schedule->status === MaintenanceStatus::Completed, 409, 'This maintenance schedule is already completed.');

        return DB::transaction(function () use ($schedule, $data, $performedBy) {
            $log = AssetMaintenanceLog::create([
                'asset_id'        => $schedule->asset_id,
                'schedule_id'     => $schedule->id,
                'performed_by'    => $performedBy,
                'action_taken'    => $data['action_taken'],
                'parts_replaced'  => $data['parts_replaced']  ?? null,
                'cost'            => $data['actual_cost']     ?? $schedule->estimated_cost,
                'condition_before' => $data['condition_before'] ?? null,
                'condition_after'  => $data['condition_after']  ?? null,
                'hours_taken'      => $data['hours_taken']      ?? null,
                'performed_at'     => $data['performed_at']     ?? now(),
            ]);

            $schedule->update([
                'status'       => MaintenanceStatus::Completed,
                'actual_cost'  => $data['actual_cost'] ?? $schedule->estimated_cost,
                'completed_at' => now(),
                'next_due_date' => $schedule->recurrence_days
                    ? now()->addDays($schedule->recurrence_days)->toDateString()
                    : null,
            ]);

            // Update the digital twin's maintenance timestamps
            $twin = AssetDigitalTwin::where('asset_id', $schedule->asset_id)->first();
            if ($twin) {
                $updates = ['last_maintenance_at' => now(), 'synced_at' => now()];
                if ($schedule->next_due_date) {
                    $updates['next_maintenance_due'] = $schedule->next_due_date;
                }
                if (! empty($data['condition_after'])) {
                    $updates['condition'] = $data['condition_after'];
                }
                $twin->update($updates);
            }

            // Update asset condition if reported
            if (! empty($data['condition_after'])) {
                $schedule->asset->update(['condition' => $data['condition_after']]);
            }

            AssetCustodyLog::create([
                'asset_id'     => $schedule->asset_id,
                'action'       => 'maintenance_completed',
                'performed_by' => $performedBy,
                'notes'        => $data['action_taken'],
                'occurred_at'  => now(),
            ]);

            // Recalculate risk now that maintenance is done
            $riskProfile = AssetRiskProfile::where('asset_id', $schedule->asset_id)->first();
            if ($riskProfile) {
                $riskProfile->load(['asset.category', 'digitalTwin']);
                $riskProfile->recalculate();
            }

            // Auto-create next recurrent schedule if configured
            if ($schedule->recurrence_days && $schedule->next_due_date) {
                (new ScheduleMaintenanceAction())->createSchedule([
                    'asset_id'         => $schedule->asset_id,
                    'title'            => $schedule->title,
                    'description'      => $schedule->description,
                    'maintenance_type' => $schedule->maintenance_type?->value ?? 'preventive',
                    'assigned_to'      => $schedule->assigned_to,
                    'vendor_name'      => $schedule->vendor_name,
                    'estimated_cost'   => $schedule->estimated_cost,
                    'due_date'         => $schedule->next_due_date,
                    'recurrence_days'  => $schedule->recurrence_days,
                    'notes'            => $schedule->notes,
                ], $performedBy);
            }

            return $log;
        });
    }
}
