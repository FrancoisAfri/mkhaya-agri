<?php

namespace App\Modules\ESign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'role'          => $this->role->value,
            'role_label'    => $this->role->label(),
            'signing_order' => $this->signing_order,
            'status'        => $this->status->value,
            'status_label'  => $this->status->label(),
            'status_color'  => $this->status->color(),
            'consent_given' => $this->consent_given,
            'signed_at'     => $this->signed_at?->toIso8601String(),
            'declined_at'   => $this->declined_at?->toIso8601String(),
        ];
    }
}
