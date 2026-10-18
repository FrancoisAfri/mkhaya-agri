<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\LossRule;

/**
 * The loss detectors' sensitivity (MFG-012). Every company starts from these defaults and may
 * tune them; what each threshold means is stated beside it, because a bare number on a settings
 * screen is how detectors end up silently switched off.
 */
class LossRules
{
    /** category => [threshold, min_count, min_impact, lookback_days, approval_over, meaning] */
    public const DEFAULTS = [
        'EXCESS_MATERIAL' => [5, 1, 0, 30, 10000, '% more material than the good and scrapped output should have used'],
        'UNEXPLAINED_STOCK_LOSS' => [0, 1, 100, 30, 10000, 'value of stock short at count above which a shortfall is raised'],
        'HIGH_SCRAP' => [5, 1, 0, 30, 10000, '% of output scrapped or rejected'],
        'REPEATED_REWORK' => [3, 3, 0, 60, 10000, 'orders with rework for the same product'],
        'ABNORMAL_MACHINE' => [20, 3, 0, 30, 10000, '% more machine time than planned at a work centre'],
        'EXCESS_DOWNTIME' => [10, 1, 0, 30, 10000, '% of a work centre\'s time lost to unplanned downtime'],
        'EXCESS_SETUP' => [25, 1, 0, 30, 10000, '% more setup and changeover time than the routings plan'],
        'EXPIRED_OBSOLETE' => [180, 1, 50, 30, 10000, 'days without movement before stock counts as obsolete'],
        'OVERSTOCK' => [26, 1, 500, 90, 10000, 'weeks of usage on hand before stock counts as excess'],
        'RUSH_ORDERS' => [3, 3, 0, 30, 10000, 'orders raised with less time than they take to make'],
        'SUPPLIER_QUALITY' => [3, 3, 0, 90, 10000, 'failed inspections of the same supplier\'s goods'],
        'OVER_STANDARD' => [5, 3, 0, 90, 10000, '% over standard cost, on this many orders of a product'],
    ];

    /**
     * The warehouse's detectors (WMS-110), on the same findings and rules. Kept apart from DEFAULTS
     * so manufacturing's own scan does not look for detectors it does not have.
     */
    public const WAREHOUSE_DEFAULTS = [
        'WH_REPEATED_ADJUSTMENTS' => [3, 3, 0, 30, 10000, 'adjustments of the same item in the same place within the window'],
        'WH_HIGH_VALUE_ADJUSTMENT' => [5, 1, 500, 90, 10000, 'times the typical adjustment value, for one adjustment'],
        'WH_DUPLICATE_DOCUMENT' => [1, 2, 0, 30, 10000, 'days within which the same quantity received or dispatched twice looks duplicated'],
        'WH_UNUSUAL_RETURNS' => [10, 3, 0, 90, 10000, '% of what was dispatched coming back, or reversals of the same item'],
        'WH_REPEATED_SHORT_PICKS' => [3, 3, 0, 30, 10000, 'short picks from the same place'],
        'WH_UNEXPECTED_LOCATION' => [0, 1, 0, 30, 10000, 'manual moves into dispatch, transit or returns areas outside a shipment, transfer or return'],
        'WH_DISPATCH_MISMATCH' => [0, 1, 0, 30, 10000, 'difference between what an order shows dispatched and what was packed and shipped'],
        'WH_EMERGENCY_PURCHASES' => [3, 3, 0, 90, 10000, 'urgent replenishment requests for the same item'],
        'WH_OUT_OF_HOURS' => [3, 3, 0, 30, 10000, 'stock corrections recorded outside the warehouse\'s working hours'],
        'WH_MANUAL' => [0, 1, 0, 30, 10000, 'a case opened by a person, not a detector'],
    ];

    public function for(int $companyId, string $category): LossRule
    {
        [$threshold, $count, $impact, $lookback, $approval] = self::DEFAULTS[$category] ?? self::WAREHOUSE_DEFAULTS[$category];

        return LossRule::query()->firstOrCreate(['company_id' => $companyId, 'category' => $category], [
            'is_active' => true, 'threshold' => $threshold, 'min_count' => $count, 'min_impact' => $impact,
            'lookback_days' => $lookback, 'approval_over' => $approval, 'due_days' => 14,
        ]);
    }

    /** @return array<string, LossRule> */
    public function all(int $companyId): array
    {
        return collect(array_keys(self::DEFAULTS))->mapWithKeys(fn ($c) => [$c => $this->for($companyId, $c)])->all();
    }

    public static function meaning(string $category): string
    {
        return (self::DEFAULTS[$category] ?? self::WAREHOUSE_DEFAULTS[$category])[5];
    }
}
