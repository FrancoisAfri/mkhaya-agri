<?php

namespace App\Modules\Assets\Http\Resources;

use App\Modules\Assets\Enums\AssetCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->asset_status) {
            'active'            => 'success',
            'unallocated'       => 'warning',
            'in_store'          => 'info',
            'under_maintenance' => 'orange',
            'disposed', 'sold'  => 'default',
            'missing'           => 'error',
            default             => 'default',
        };

        $conditionColor = match ($this->condition) {
            'new', 'good' => 'success',
            'fair'        => 'warning',
            'poor'        => 'orange',
            'damaged'     => 'error',
            default       => 'default',
        };

        return [
            'id'           => $this->id,
            'uuid'         => $this->uuid,
            'asset_number' => $this->asset_number,
            'asset_tag'    => $this->asset_tag,
            'name'         => $this->name,
            'description'  => $this->when(isset($this->description), $this->description),
            'serial_number'=> $this->serial_number,
            'model_number' => $this->model_number,
            'make_number'  => $this->make_number,
            'make'  => $this->whenLoaded('make',  fn() => $this->make  ? ['id' => $this->make->id,  'name' => $this->make->name]  : null),
            'model' => $this->whenLoaded('model', fn() => $this->model ? ['id' => $this->model->id, 'name' => $this->model->name] : null),
            'barcode'      => $this->when(isset($this->barcode), $this->barcode),
            'status'       => [
                'value' => $this->asset_status,
                'label' => ucwords(str_replace('_', ' ', $this->asset_status ?? 'unknown')),
                'color' => $statusColor,
            ],
            'condition' => $this->condition ? [
                'value' => $this->condition,
                'label' => AssetCondition::tryFrom($this->condition)?->label() ?? ucfirst($this->condition),
                'color' => $conditionColor,
            ] : null,
            'purchase_cost'    => $this->purchase_cost,
            'purchase_date'    => $this->purchase_date?->toDateString(),
            'warranty_expiry'  => $this->warranty_expiry?->toDateString(),
            'retention_until'  => $this->retention_until?->toDateString(),
            'last_audited_at'  => $this->last_audited_at?->toISOString(),
            'asset_type'       => $this->whenLoaded('AssetType', fn() => [
                'id'   => $this->AssetType->id,
                'name' => $this->AssetType->name ?? 'Unknown',
            ]),
            'category' => $this->whenLoaded('category', fn() => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'location' => $this->whenLoaded('location', fn() => [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ]),
            'current_assignment' => $this->whenLoaded('currentAssignment', fn() => $this->currentAssignment ? [
                'id'          => $this->currentAssignment->id,
                'assigned_to' => $this->currentAssignment->assigned_to,
                'assignee'    => $this->currentAssignment->relationLoaded('assignedTo')
                    ? $this->currentAssignment->assignedTo?->name
                    : null,
                'effective_date'  => $this->currentAssignment->effective_date?->toDateString(),
                'return_due_date' => $this->currentAssignment->return_due_date?->toDateString(),
            ] : null),
            'images' => $this->whenLoaded('images', fn() => $this->images->map(fn($img) => [
                'id'            => $img->id,
                'url'           => $img->url,
                'original_name' => $img->original_name,
                'sort_order'    => $img->sort_order,
            ])),
            'assignments_count'           => $this->whenCounted('assignments'),
            'maintenance_schedules_count' => $this->whenCounted('maintenanceSchedules'),
            'incidents_count'             => $this->whenCounted('incidents'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
