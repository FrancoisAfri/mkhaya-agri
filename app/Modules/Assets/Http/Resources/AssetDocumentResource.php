<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'asset_id'             => $this->asset_id,
            'document_type'        => $this->document_type,
            'file_path'            => $this->file_path,
            'original_filename'    => $this->original_filename,
            'mime_type'            => $this->mime_type,
            'size_bytes'           => $this->size_bytes,
            'extraction_status'    => $this->extraction_status,
            'uploaded_by_user_id'  => $this->uploaded_by_user_id,
            'asset' => $this->whenLoaded('asset', fn () => [
                'id'           => $this->asset->id,
                'uuid'         => $this->asset->uuid,
                'name'         => $this->asset->name,
                'asset_number' => $this->asset->asset_number,
            ]),
            'extraction' => $this->whenLoaded('extraction', fn () => [
                'id'                => $this->extraction?->id,
                'validation_status' => $this->extraction?->validation_status,
                'confidence_score'  => $this->extraction?->confidence_score,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
