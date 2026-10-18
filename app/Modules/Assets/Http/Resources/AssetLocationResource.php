<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'uuid'       => $this->uuid,
            'code'       => $this->code,
            'name'       => $this->name,
            'type'       => $this->type,
            'parent_id'  => $this->parent_id,
            'full_path'  => method_exists($this->resource, 'fullPath') ? $this->fullPath() : $this->name,
            'gps_lat'    => $this->gps_lat,
            'gps_lng'    => $this->gps_lng,
            'status'     => $this->status,
            'parent'     => $this->whenLoaded('parent', fn () => [
                'id'   => $this->parent->id,
                'name' => $this->parent->name,
            ]),
            'assets_count' => $this->whenCounted('assets'),
        ];
    }
}
