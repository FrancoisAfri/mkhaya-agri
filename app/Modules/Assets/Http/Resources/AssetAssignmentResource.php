<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'assignment_type' => $this->assignment_type->value ?? $this->assignment_type,
            'effective_date'  => $this->effective_date?->toDateString(),
            'return_due_date' => $this->return_due_date?->toDateString(),
            'returned_at'     => $this->returned_at?->toISOString(),
            'is_current'      => $this->is_current,
            'notes'           => $this->notes,
            'assignee'        => $this->whenLoaded('assignedTo', fn() => [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'assigned_by'     => $this->whenLoaded('assignedBy', fn() => [
                'id'   => $this->assignedBy->id,
                'name' => $this->assignedBy->name,
            ]),
            'location'        => $this->whenLoaded('location', fn() => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
