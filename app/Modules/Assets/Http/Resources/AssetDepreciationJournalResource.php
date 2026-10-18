<?php

namespace App\Modules\Assets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetDepreciationJournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'uuid'                 => $this->uuid,
            'asset_id'             => $this->asset_id,
            'period_start'         => $this->period_start?->toDateString(),
            'period_end'           => $this->period_end?->toDateString(),
            'method'               => $this->method,
            'track'                => $this->track,
            'opening_book_value'   => $this->opening_book_value,
            'depreciation_amount'  => $this->depreciation_amount ?? $this->amount,
            'closing_book_value'   => $this->closing_book_value,
            'is_posted'            => $this->is_posted ?? null,
            'posted_at'            => $this->posted_at?->toISOString(),
            'asset' => $this->whenLoaded('asset', fn () => [
                'id'           => $this->asset->id,
                'name'         => $this->asset->name,
                'asset_number' => $this->asset->asset_number,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
