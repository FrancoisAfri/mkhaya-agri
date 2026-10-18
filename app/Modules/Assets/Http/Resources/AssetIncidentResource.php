<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetIncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $severityColor = match ($this->severity?->value ?? $this->severity) {
            'low'      => 'info',
            'medium'   => 'warning',
            'high'     => 'error',
            'critical' => 'error',
            default    => 'default',
        };

        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'title'            => $this->title,
            'description'      => $this->description,
            'severity'         => [
                'value' => $this->severity?->value ?? $this->severity,
                'label' => ucfirst($this->severity?->value ?? $this->severity ?? ''),
                'color' => $severityColor,
            ],
            'repair_cost'      => $this->repair_cost,
            'is_resolved'      => $this->is_resolved,
            'resolved_at'      => $this->resolved_at?->toISOString(),
            'resolution_notes' => $this->resolution_notes,
            'occurred_at'      => $this->occurred_at?->toISOString(),
            'reported_by'      => $this->whenLoaded('reportedBy', fn() => $this->reportedBy ? [
                'id'   => $this->reportedBy->id,
                'name' => $this->reportedBy->name,
            ] : null),
            'asset'            => $this->whenLoaded('asset', fn() => $this->asset ? [
                'uuid' => $this->asset->uuid,
                'name' => $this->asset->name,
                'asset_tag' => $this->asset->asset_tag,
            ] : null),
            'created_at'       => $this->created_at->toISOString(),
        ];
    }
}
