<?php

namespace App\Modules\Assets\Actions\DigitalTwin;

use App\Modules\Assets\Events\AssetIncidentReported;
use App\Modules\Assets\Models\AssetIncident;
use Illuminate\Support\Facades\DB;

class RecordAssetIncidentAction
{
    public function execute(array $data): AssetIncident
    {
        return DB::transaction(function () use ($data) {
            $incident = AssetIncident::create([
                'asset_id'              => $data['asset_id'],
                'incident_type'         => $data['incident_type'],
                'severity'              => $data['severity'],
                'incident_date'         => $data['incident_date'] ?? now(),
                'reported_by_user_id'   => $data['reported_by_user_id'],
                'description'           => $data['description'],
                'root_cause'            => $data['root_cause'] ?? null,
                'corrective_action'     => $data['corrective_action'] ?? null,
                'cost_of_repair'        => $data['cost_of_repair'] ?? null,
                'downtime_hours'        => $data['downtime_hours'] ?? null,
                'insurance_claim_number' => $data['insurance_claim_number'] ?? null,
                'police_case_number'    => $data['police_case_number'] ?? null,
            ]);

            event(new AssetIncidentReported($incident));

            return $incident->fresh();
        });
    }
}
