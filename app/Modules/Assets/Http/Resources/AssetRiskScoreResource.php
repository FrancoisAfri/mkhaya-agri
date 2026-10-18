<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetRiskScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $levelColor = match ($this->risk_level) {
            'critical' => 'error',
            'high'     => 'orange',
            'medium'   => 'warning',
            'low'      => 'success',
            default    => 'default',
        };

        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'asset_id'           => $this->asset_id,
            'score_date'         => $this->score_date?->toDateString(),
            'risk_score'         => $this->risk_score,
            'risk_level' => [
                'value' => $this->risk_level,
                'label' => ucfirst((string) $this->risk_level),
                'color' => $levelColor,
            ],
            'maintenance_score'  => $this->maintenance_score,
            'incident_score'     => $this->incident_score,
            'utilization_score'  => $this->utilization_score,
            'compliance_score'   => $this->compliance_score,
            'anomalies_detected' => $this->anomalies_detected,
            'recommendations'    => $this->recommendations,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id'   => $this->asset->id,
                'uuid' => $this->asset->uuid,
                'name' => $this->asset->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
