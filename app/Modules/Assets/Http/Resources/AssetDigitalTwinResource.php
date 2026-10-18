<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetDigitalTwinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'uuid'                => $this->uuid,
            'condition'           => $this->condition?->value ?? $this->condition,
            'operating_hours'     => $this->operating_hours,
            'odometer_reading'    => $this->odometer_reading,
            'health_score'        => $this->health_score,
            'utilization_rate'    => $this->utilization_rate,
            'sensor_data'         => $this->sensor_data,
            'last_maintenance_at' => $this->last_maintenance_at?->toISOString(),
            'next_maintenance_due'=> $this->next_maintenance_due?->toDateString(),
            'synced_at'           => $this->synced_at?->toISOString(),
            'current_location'    => $this->whenLoaded('currentLocation', fn() => $this->currentLocation ? [
                'id'   => $this->currentLocation->id,
                'name' => $this->currentLocation->name,
            ] : null),
            'risk_profile'        => $this->whenLoaded('riskProfile', fn() => $this->riskProfile ? [
                'risk_level'       => $this->riskProfile->risk_level?->value ?? $this->riskProfile->risk_level,
                'overall_score'    => $this->riskProfile->overall_score,
                'age_score'        => $this->riskProfile->age_score,
                'maintenance_score'=> $this->riskProfile->maintenance_score,
                'incident_score'   => $this->riskProfile->incident_score,
                'last_calculated_at' => $this->riskProfile->last_calculated_at?->toISOString(),
            ] : null),
        ];
    }
}
