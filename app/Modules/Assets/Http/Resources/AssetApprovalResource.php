<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $statusColor = match ($this->status) {
            'approved'                    => 'success',
            'pending', 'in_review'        => 'warning',
            'rejected'                    => 'error',
            'cancelled'                   => 'default',
            default                       => 'info',
        };

        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'type'            => $this->type ?? $this->approval_type,
            'approvable_type' => $this->approvable_type,
            'approvable_id'   => $this->approvable_id,
            'requested_by'    => $this->requested_by,
            'current_step'    => $this->current_step,
            'total_steps'     => $this->total_steps,
            'status' => [
                'value' => $this->status,
                'label' => ucfirst(str_replace('_', ' ', (string) $this->status)),
                'color' => $statusColor,
            ],
            'amount'         => $this->amount,
            'requester'      => $this->whenLoaded('requester', fn () => [
                'id'   => $this->requester->id,
                'name' => $this->requester->name ?? 'Unknown',
            ]),
            'steps'          => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($s) => [
                'step_number' => $s->step_number,
                'approver_id' => $s->approver_id,
                'decision'    => $s->decision,
                'decided_at'  => $s->decided_at?->toISOString(),
                'comments'    => $s->comments,
            ])),
            'comments_count' => $this->whenCounted('comments'),
            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
