<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetMaintenanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->status?->value ?? $this->status) {
            'scheduled'   => 'info',
            'in_progress' => 'warning',
            'completed'   => 'success',
            'overdue'     => 'error',
            'cancelled'   => 'default',
            default       => 'default',
        };

        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'title'            => $this->title,
            'description'      => $this->description,
            'maintenance_type' => $this->maintenance_type?->value ?? $this->maintenance_type,
            'status'           => [
                'value' => $this->status?->value ?? $this->status,
                'label' => $this->status?->label() ?? ucfirst($this->status ?? ''),
                'color' => $statusColor,
            ],
            'vendor_name'     => $this->vendor_name,
            'estimated_cost'  => $this->estimated_cost,
            'actual_cost'     => $this->actual_cost,
            'due_date'        => $this->due_date?->toDateString(),
            'started_at'      => $this->started_at?->toISOString(),
            'completed_at'    => $this->completed_at?->toISOString(),
            'next_due_date'   => $this->next_due_date?->toDateString(),
            'recurrence_days' => $this->recurrence_days,
            'notes'           => $this->notes,
            'assigned_to'     => $this->whenLoaded('assignedTo', fn() => $this->assignedTo ? [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
