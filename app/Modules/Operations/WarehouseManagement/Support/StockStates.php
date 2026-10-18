<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

use App\Modules\Finance\Accounting\Models\FinStockPosition;

/**
 * WMS-021: what every stock state counts towards, in one table every figure is computed from.
 *
 * Physical is what is on the premises (or on a truck between them). Available is what may be
 * promised and picked. Valuation is what sits on the balance sheet: damaged, quarantined and
 * rejected stock is still this company's asset until it is written off, which is a separate,
 * reasoned adjustment -- so it is still valued here. Expired is not a stored state: it is an
 * AVAILABLE position in a lot whose expiry has passed, and availability excludes it by date.
 */
final class StockStates
{
    /** state => [physical, available, fulfilment, valuation] */
    public const RULES = [
        FinStockPosition::AVAILABLE => [true, true, true, true],
        FinStockPosition::QUARANTINE => [true, false, false, true],
        FinStockPosition::ON_HOLD => [true, false, false, true],
        FinStockPosition::INSPECTION => [true, false, false, true],
        FinStockPosition::DAMAGED => [true, false, false, true],
        FinStockPosition::REJECTED => [true, false, false, true],
        FinStockPosition::RECALLED => [true, false, false, true],
    ];

    /** Pseudo-states derived rather than stored, listed so the definitions screen is complete. */
    public const DERIVED = [
        'IN_TRANSIT' => [true, false, false, true],   // positions in an IN_TRANSIT location
        'EXPIRED' => [true, false, false, true],      // AVAILABLE in a lot past its expiry date
        'RESERVED' => [true, false, false, true],     // AVAILABLE but held by an open reservation
    ];

    public static function countsAsAvailable(string $state): bool
    {
        return self::RULES[$state][1] ?? false;
    }

    /** @return list<array{state:string, derived:bool, physical:bool, available:bool, fulfilment:bool, valuation:bool}> */
    public static function table(): array
    {
        $rows = [];
        foreach ([self::RULES, self::DERIVED] as $i => $set) {
            foreach ($set as $state => [$physical, $available, $fulfilment, $valuation]) {
                $rows[] = compact('state', 'physical', 'available', 'fulfilment', 'valuation') + ['derived' => $i === 1];
            }
        }

        return $rows;
    }
}
