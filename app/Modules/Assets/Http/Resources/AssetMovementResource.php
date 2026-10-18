<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'reason'          => $this->reason,
            'gps_lat'         => $this->gps_lat,
            'gps_lng'         => $this->gps_lng,
            'moved_at'        => $this->moved_at?->toISOString(),
            'from_location'   => $this->whenLoaded('fromLocation', fn() => $this->fromLocation ? [
                'id'   => $this->fromLocation->id,
                'name' => $this->fromLocation->name,
            ] : null),
            'to_location'     => $this->whenLoaded('toLocation', fn() => $this->toLocation ? [
                'id'   => $this->toLocation->id,
                'name' => $this->toLocation->name,
            ] : null),
            'moved_by'        => $this->whenLoaded('movedBy', fn() => $this->movedBy ? [
                'id'   => $this->movedBy->id,
                'name' => $this->movedBy->name,
            ] : null),
            'created_at'      => $this->created_at->toISOString(),
        ];
    }
}
