<?php

namespace App\Modules\ESign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnvelopeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'title'         => $this->title,
            'document_type' => $this->document_type->value,
            'type_label'    => $this->document_type->label(),
            'status'        => $this->status->value,
            'status_label'  => $this->status->label(),
            'status_color'  => $this->status->color(),
            'message'       => $this->message,
            'expires_at'    => $this->expires_at?->toIso8601String(),
            'sent_at'       => $this->sent_at?->toIso8601String(),
            'completed_at'  => $this->completed_at?->toIso8601String(),
            'voided_at'            => $this->voided_at?->toIso8601String(),
            'voided_reason'        => $this->voided_reason,
            'has_certificate'      => !empty($this->signed_document_path),
            'has_document'         => !empty($this->document_path) || !empty($this->executed_document_path),
            'has_executed'         => !empty($this->executed_document_path),
            // Shown so somebody can check the copy they downloaded is the copy that was signed.
            'document_hash'        => $this->document_hash,
            'executed_hash'        => $this->executed_hash,
            'created_at'           => $this->created_at->toIso8601String(),

            /*
             * resolve(), because a nested resource collection is not unwrapped by the outer
             * resource's own resolve(). Without it the page received
             * `signers: {"data": [...]}` where it expected an array — so `v-for` iterated the
             * object, found the single key `data`, and drew one blank signer row with no name,
             * no email and no status. The envelope looked as though it had a signer nobody could
             * identify, on the screen whose job is to show you who still has to sign.
             */
            'signers' => $this->whenLoaded('signers',
                fn() => SignerResource::collection($this->signers)->resolve()
            ),

            'audit_entries' => $this->whenLoaded('auditEntries', fn() =>
                $this->auditEntries->map(fn($e) => [
                    'event'       => $e->event,
                    'actor_name'  => $e->actor_name,
                    'actor_email' => $e->actor_email,
                    'metadata'    => $e->metadata,
                    'created_at'  => $e->created_at->toIso8601String(),
                ])
            ),
        ];
    }
}
