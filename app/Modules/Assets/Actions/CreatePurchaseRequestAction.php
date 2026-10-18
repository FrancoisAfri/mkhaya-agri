<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetPurchaseRequest;

class CreatePurchaseRequestAction
{
    public function execute(array $data, int $requestedBy): AssetPurchaseRequest
    {
        $year     = now()->year;
        $sequence = AssetPurchaseRequest::whereYear('created_at', $year)->count() + 1;
        $number   = 'PR-' . $year . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);

        $totalCost = ($data['quantity'] ?? 1) * $data['estimated_unit_cost'];

        return AssetPurchaseRequest::create([
            'request_number'       => $number,
            'requested_by'         => $requestedBy,
            'item_description'     => $data['item_description'],
            'asset_category_id'    => $data['asset_category_id'] ?? null,
            'quantity'             => $data['quantity'] ?? 1,
            'estimated_unit_cost'  => $data['estimated_unit_cost'],
            'estimated_total_cost' => $totalCost,
            'justification'        => $data['justification'],
            'priority'             => $data['priority'] ?? 'medium',
            'status'               => 'draft',
        ]);
    }
}
