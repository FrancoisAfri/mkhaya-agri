<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class RecordAssetMovementAction
{
    public function execute(array $data, int $movedBy): AssetMovement
    {
        return DB::transaction(function () use ($data, $movedBy) {
            $asset = Assets::findOrFail($data['asset_id']);

            $twin = AssetDigitalTwin::firstOrCreate(
                ['asset_id' => $asset->id],
                ['condition' => $asset->condition ?? 'good', 'synced_at' => now()]
            );

            $fromLocationId = $twin->current_location_id;

            $movement = AssetMovement::create([
                'asset_id'        => $asset->id,
                'digital_twin_id' => $twin->id,
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $data['to_location_id'],
                'moved_by'         => $movedBy,
                'reason'           => $data['reason']  ?? null,
                'gps_lat'          => $data['gps_lat'] ?? null,
                'gps_lng'          => $data['gps_lng'] ?? null,
                'moved_at'         => $data['moved_at'] ?? now(),
            ]);

            $twin->update([
                'current_location_id' => $data['to_location_id'],
                'synced_at'           => now(),
            ]);

            $asset->update(['location_id' => $data['to_location_id']]);

            AssetCustodyLog::create([
                'asset_id'        => $asset->id,
                'action'          => 'moved',
                'from_location_id' => $fromLocationId,
                'to_location_id'   => $data['to_location_id'],
                'performed_by'    => $movedBy,
                'notes'           => $data['reason'] ?? null,
                'occurred_at'     => now(),
            ]);

            return $movement;
        });
    }
}
