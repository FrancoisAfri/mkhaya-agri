<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetExitClearanceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'exit_clearance_id' => $this->exit_clearance_id,
            'asset_id'          => $this->asset_id,
            'status'            => $this->status,
            'return_condition'  => $this->return_condition,
            'liability_amount'  => $this->liability_amount,
            'notes'             => $this->notes,
            'asset'             => $this->whenLoaded('asset', fn () => [
                'id'           => $this->asset->id,
                'uuid'         => $this->asset->uuid,
                'name'         => $this->asset->name,
                'asset_number' => $this->asset->asset_number,
            ]),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
