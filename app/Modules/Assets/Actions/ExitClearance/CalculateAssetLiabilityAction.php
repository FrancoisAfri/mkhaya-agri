<?php

namespace App\Modules\Assets\Actions\ExitClearance;

use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\AssetLiability;
use Illuminate\Support\Facades\DB;

class CalculateAssetLiabilityAction
{
    public function execute(AssetExitClearanceItem $item, string $liabilityType): AssetLiability
    {
        return DB::transaction(function () use ($item, $liabilityType) {
            $asset = $item->asset;
            
            // Get current book value from latest depreciation
            $bookValue = $asset->depreciations()
                ->orderByDesc('created_at')
                ->value('balance_amount') ?? $asset->purchase_cost;

            // Calculate liability (100% for missing, 50% for damaged)
            $liabilityAmount = match ($liabilityType) {
                'missing', 'lost', 'stolen' => $bookValue,
                'damaged'                   => $bookValue * 0.5,
                default                     => 0,
            };

            $liability = AssetLiability::create([
                'exit_clearance_id'  => $item->exit_clearance_id,
                'asset_id'           => $item->asset_id,
                'employee_id'        => $item->exitClearance->employee_id,
                'liability_type'     => $liabilityType,
                'asset_book_value'   => $bookValue,
                'liability_amount'   => $liabilityAmount,
                'settlement_status'  => 'pending',
            ]);

            // Update clearance total
            $item->exitClearance->increment('total_liability_amount', $liabilityAmount);

            return $liability;
        });
    }
}
