<?php

namespace App\Modules\Assets\Actions\DigitalTwin;

use App\Modules\Assets\Events\AssetMoved;
use App\Modules\Assets\Models\AssetMovement;
use Illuminate\Support\Facades\DB;

class RecordAssetMovementAction
{
    public function execute(array $data): AssetMovement
    {
        return DB::transaction(function () use ($data) {
            $movement = AssetMovement::create([
                'asset_id'          => $data['asset_id'],
                'from_location_id'  => $data['from_location_id'] ?? null,
                'to_location_id'    => $data['to_location_id'],
                'moved_by_user_id'  => $data['moved_by_user_id'],
                'movement_date'     => $data['movement_date'] ?? now(),
                'movement_type'     => $data['movement_type'] ?? 'transfer',
                'reason'            => $data['reason'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'gps_from'          => $data['gps_from'] ?? null,
                'gps_to'            => $data['gps_to'] ?? null,
            ]);

            // Update asset location
            $movement->asset->update([
                'location_id' => $data['to_location_id'],
            ]);

            event(new AssetMoved($movement));

            return $movement->fresh();
        });
    }
}
