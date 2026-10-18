<?php

namespace App\Modules\HR\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShortlistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'status'      => $this->status->value,
            'status_label'=> $this->status->label(),
            'notes'       => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'generated_by'=> $this->whenLoaded('generatedBy', fn() => [
                'id'   => $this->generatedBy->id,
                'name' => $this->generatedBy->name,
            ]),
            'approved_by' => $this->whenLoaded('approvedBy', fn() => [
                'id'   => $this->approvedBy?->id,
                'name' => $this->approvedBy?->name,
            ]),
            'items' => $this->whenLoaded('items', fn() =>
                $this->items->map(fn($item) => [
                    'id'             => $item->id,
                    'rank'           => $item->rank,
                    'ai_recommended' => $item->ai_recommended,
                    'candidate'      => new CandidateResource($item->candidate),
                    'fit_score'      => $item->application?->fitScore?->overall_score,
                ])
            ),
            'overrides' => $this->whenLoaded('overrides', fn() =>
                $this->overrides->map(fn($o) => [
                    'id'              => $o->id,
                    'action'          => $o->action,
                    'override_reason' => $o->override_reason,
                    'approved_at'     => $o->approved_at?->toIso8601String(),
                    'overridden_by'   => ['id' => $o->overriddenBy->id, 'name' => $o->overriddenBy->name],
                ])
            ),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
