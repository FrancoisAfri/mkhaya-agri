<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetExitClearanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->status) {
            'completed'           => 'success',
            'pending', 'in_review'=> 'warning',
            'cancelled'           => 'default',
            default               => 'info',
        };

        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'employee_id'            => $this->employee_id,
            'clearance_deadline'     => $this->clearance_deadline?->toDateString(),
            'total_liability_amount' => $this->total_liability_amount,
            'status' => [
                'value' => $this->status,
                'label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
                'color' => $statusColor,
            ],
            'items' => $this->whenLoaded('items', fn () => AssetExitClearanceItemResource::collection($this->items)),
            'items_count'    => $this->whenCounted('items'),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
