<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetSetting;
use App\Modules\Assets\Models\AssetWorkOrder;

/**
 * Rolls up the cost of a work order from its parts lines and labour hours.
 *
 *   parts_cost = Σ line_cost
 *   labor_cost = labor_hours × labor_hourly_rate  (AssetSetting, default 0)
 *   total_cost = labor_cost + parts_cost
 *
 * Call after any change to parts or labour hours.
 */
class WorkOrderCostService
{
    public function recalculateCosts(AssetWorkOrder $wo): AssetWorkOrder
    {
        $rate = (float) AssetSetting::get('labor_hourly_rate', 0);

        $partsCost = (float) $wo->parts()->sum('line_cost');
        $laborCost = round((float) $wo->labor_hours * $rate, 2);

        $wo->parts_cost = $partsCost;
        $wo->labor_cost = $laborCost;
        $wo->total_cost = round($laborCost + $partsCost, 2);
        $wo->save();

        return $wo;
    }
}
