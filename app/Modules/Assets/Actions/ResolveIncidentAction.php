<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetRiskProfile;
use Illuminate\Support\Facades\DB;

class ResolveIncidentAction
{
    public function execute(AssetIncident $incident, array $data, int $resolvedBy): AssetIncident
    {
        return DB::transaction(function () use ($incident, $data, $resolvedBy) {
            $incident->update([
                'is_resolved'      => true,
                'resolved_at'      => now(),
                'resolution_notes' => $data['resolution_notes'] ?? null,
                'repair_cost'      => $data['repair_cost'] ?? $incident->repair_cost,
            ]);

            AssetCustodyLog::create([
                'asset_id'     => $incident->asset_id,
                'action'       => 'incident_resolved',
                'performed_by' => $resolvedBy,
                'notes'        => "Resolved: {$incident->title}",
                'occurred_at'  => now(),
            ]);

            $riskProfile = AssetRiskProfile::where('asset_id', $incident->asset_id)->first();
            if ($riskProfile) {
                $riskProfile->load(['asset.category', 'digitalTwin']);
                $riskProfile->recalculate();
            }

            return $incident->refresh();
        });
    }
}
