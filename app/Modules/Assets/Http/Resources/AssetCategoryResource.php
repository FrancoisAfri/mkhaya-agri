<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'uuid'                        => $this->uuid,
            'name'                        => $this->name,
            'parent_id'                   => $this->parent_id,
            'sars_allowance_section'      => $this->sars_allowance_section,
            'default_depreciation_method' => $this->default_depreciation_method,
            'default_useful_life_years'   => $this->default_useful_life_years,
            'default_salvage_percent'     => $this->default_salvage_percent,
            'status'                      => $this->status,
            'parent'                      => $this->whenLoaded('parent', fn () => [
                'id'   => $this->parent->id,
                'name' => $this->parent->name,
            ]),
            'children_count'              => $this->whenCounted('children'),
            'assets_count'                => $this->whenCounted('assets'),
            'created_at'                  => $this->created_at?->toISOString(),
        ];
    }
}
