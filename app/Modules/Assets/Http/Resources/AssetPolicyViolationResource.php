<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetPolicyViolationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->status) {
            'resolved', 'exception_granted' => 'success',
            'open'                          => 'error',
            'acknowledged'                  => 'warning',
            default                         => 'default',
        };

        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'asset_id'       => $this->asset_id,
            'user_id'        => $this->user_id,
            'policy_rule_id' => $this->policy_rule_id,
            'severity'       => $this->severity,
            'description'    => $this->description,
            'status' => [
                'value' => $this->status,
                'label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
                'color' => $statusColor,
            ],
            'asset' => $this->whenLoaded('asset', fn () => [
                'id'   => $this->asset->id,
                'uuid' => $this->asset->uuid,
                'name' => $this->asset->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
