<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetProcurementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->status) {
            'capitalised', 'received' => 'success',
            'approved', 'ordered'     => 'info',
            'requested'               => 'warning',
            'cancelled'               => 'default',
            default                   => 'default',
        };

        return [
            'id'                      => $this->id,
            'uuid'                    => $this->uuid,
            'requested_by_user_id'    => $this->requested_by_user_id,
            'asset_category_id'       => $this->asset_category_id,
            'supplier_id'             => $this->supplier_id,
            'quantity'                => $this->quantity,
            'unit_price'              => $this->unit_price,
            'total_amount'            => $this->total_amount,
            'requested_delivery_date' => $this->requested_delivery_date?->toDateString(),
            'justification'           => $this->justification,
            'status' => [
                'value' => $this->status,
                'label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
                'color' => $statusColor,
            ],
            'category' => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'   => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
