<?php

namespace App\Modules\Assets\Actions\Maintenance;

use App\Modules\Assets\Events\AssetMaintenanceScheduled;
use App\Modules\Assets\Models\AssetMaintenance;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use Illuminate\Support\Facades\DB;

class ScheduleMaintenanceAction
{
    public function execute(array $data): AssetMaintenance
    {
        return DB::transaction(function () use ($data) {
            $maintenance = AssetMaintenance::create([
                'asset_id'                 => $data['asset_id'],
                'maintenance_schedule_id'  => $data['maintenance_schedule_id'] ?? null,
                'maintenance_type'         => $data['maintenance_type'],
                'maintenance_date'         => $data['maintenance_date'],
                'performed_by_user_id'     => $data['performed_by_user_id'] ?? null,
                'vendor_name'              => $data['vendor_name'] ?? null,
                'vendor_contact'           => $data['vendor_contact'] ?? null,
                'description'              => $data['description'],
                'cost'                     => $data['cost'] ?? null,
                'downtime_hours'           => $data['downtime_hours'] ?? null,
                'status'                   => 'scheduled',
            ]);

            event(new AssetMaintenanceScheduled($maintenance));

            return $maintenance->fresh();
        });
    }
}
