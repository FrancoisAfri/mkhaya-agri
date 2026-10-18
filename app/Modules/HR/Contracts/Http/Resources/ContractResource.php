<?php

namespace App\Modules\HR\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'status'          => $this->status->value,
            'status_label'    => $this->status->label(),
            'sent_at'         => $this->sent_at?->toIso8601String(),
            'expires_at'      => $this->expires_at?->toIso8601String(),
            'fully_signed_at' => $this->fully_signed_at?->toIso8601String(),

            'candidate' => $this->whenLoaded('candidate', fn() => [
                'id'        => $this->candidate->id,
                'uuid'      => $this->candidate->uuid,
                'full_name' => $this->candidate->full_name,
                'email'     => $this->candidate->email,
            ]),
            'job' => $this->whenLoaded('job', fn() => [
                'id'    => $this->job->id,
                'title' => $this->job->title,
            ]),
            'signature_requests' => $this->whenLoaded('signatureRequests', fn() =>
                $this->signatureRequests->map(fn($sr) => [
                    'id'             => $sr->id,
                    'uuid'           => $sr->uuid,
                    'signatory_type' => $sr->signatory_type,
                    'signed_at'      => $sr->signed_at?->toIso8601String(),
                    'is_signed'      => $sr->isSigned(),
                ])
            ),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
