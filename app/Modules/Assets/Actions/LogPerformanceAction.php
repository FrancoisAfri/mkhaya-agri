<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetPerformanceLog;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class LogPerformanceAction
{
    public function execute(array $data, int $loggedBy): AssetPerformanceLog
    {
        return DB::transaction(function () use ($data, $loggedBy) {
            $asset = Assets::findOrFail($data['asset_id']);

            $twin = AssetDigitalTwin::firstOrCreate(
                ['asset_id' => $asset->id],
                ['condition' => $asset->condition ?? 'good', 'synced_at' => now()]
            );

            $log = AssetPerformanceLog::create([
                'asset_id'              => $asset->id,
                'digital_twin_id'       => $twin->id,
                'logged_by'             => $loggedBy,
                'operating_hours_delta' => $data['operating_hours_delta'] ?? null,
                'odometer_delta'        => $data['odometer_delta']        ?? null,
                'utilization_rate'      => $data['utilization_rate']      ?? null,
                'downtime_minutes'      => $data['downtime_minutes']      ?? null,
                'metrics'               => $data['metrics']               ?? null,
                'logged_at'             => $data['logged_at']             ?? now(),
            ]);

            $twinUpdates = ['synced_at' => now()];

            if (isset($data['operating_hours_delta'])) {
                $twinUpdates['operating_hours'] = ($twin->operating_hours ?? 0) + $data['operating_hours_delta'];
            }
            if (isset($data['odometer_delta'])) {
                $twinUpdates['odometer_reading'] = ($twin->odometer_reading ?? 0) + $data['odometer_delta'];
            }
            if (isset($data['utilization_rate'])) {
                $twinUpdates['utilization_rate'] = $data['utilization_rate'];
            }

            $twin->update($twinUpdates);

            return $log;
        });
    }
}
