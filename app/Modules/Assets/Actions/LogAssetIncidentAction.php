<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetDigitalTwin;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetRiskProfile;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class LogAssetIncidentAction
{
    public function execute(array $data, int $reportedBy): AssetIncident
    {
        return DB::transaction(function () use ($data, $reportedBy) {
            $asset = Assets::findOrFail($data['asset_id']);

            $twin = AssetDigitalTwin::firstOrCreate(
                ['asset_id' => $asset->id],
                ['synced_at' => now()]
            );

            $incident = AssetIncident::create([
                'asset_id'        => $asset->id,
                'digital_twin_id' => $twin->id,
                'reported_by'     => $reportedBy,
                'title'           => $data['title'],
                'description'     => $data['description']  ?? null,
                'severity'        => $data['severity']     ?? 'low',
                'repair_cost'     => $data['repair_cost']  ?? null,
                'is_resolved'     => false,
                'occurred_at'     => $data['occurred_at']  ?? now(),
            ]);

            // Update twin condition if provided
            if (! empty($data['condition_after'])) {
                $twin->update(['condition' => $data['condition_after'], 'synced_at' => now()]);
                $asset->update(['condition' => $data['condition_after']]);
            }

            AssetCustodyLog::create([
                'asset_id'     => $asset->id,
                'action'       => 'incident_reported',
                'performed_by' => $reportedBy,
                'notes'        => "[{$data['severity']}] {$data['title']}",
                'occurred_at'  => now(),
            ]);

            // Recalculate risk profile after an incident
            $riskProfile = AssetRiskProfile::firstOrCreate(
                ['asset_id' => $asset->id],
                ['digital_twin_id' => $twin->id, 'last_calculated_at' => now()]
            );
            $riskProfile->load(['asset.category', 'digitalTwin']);
            $riskProfile->recalculate();

            return $incident;
        });
    }
}
