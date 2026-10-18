<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Services\UomConverter;
use DomainException;
use RuntimeException;

/**
 * A quantity in whatever unit somebody counted it in, turned into the item's base unit (WMS-011).
 *
 * Returns both, so a movement can carry "3 ctn" next to the 36 units the balance moves by. A unit
 * the item has no conversion for is refused with the reason -- never treated as 1:1, which is how
 * 3 cartons quietly becomes 3 units.
 */
class UnitQuantity
{
    public function __construct(private readonly UomConverter $converter, private readonly ItemCatalogue $catalogue) {}

    /** @return array{base_qty: float, txn_uom_id: int, txn_qty: float, factor: float} */
    public function normalise(FinStockItem $item, float $qty, ?int $uomId): array
    {
        $base = $this->catalogue->baseUom($item);
        $uom = $uomId ? Uom::query()->findOrFail($uomId) : $base;

        try {
            $factor = $this->converter->convert(1, $uom, $base, $item->id);
        } catch (DomainException $e) {
            throw new RuntimeException("{$item->code} has no conversion from {$uom->code} to {$base->code}. Add it as a packaging level first.");
        }

        $baseQty = round($qty * $factor, 4);
        $places = (int) ($base->decimal_places ?? 4);
        $variable = (bool) \App\Modules\Operations\WarehouseManagement\Models\ItemProfile::query()
            ->where('stock_item_id', $item->id)->value('is_variable_weight');
        if ($places === 0 && abs($baseQty - round($baseQty)) > 1e-6 && ! $variable) {
            throw new RuntimeException("{$qty} {$uom->code} is {$baseQty} {$base->code}; {$base->code} cannot be split.");
        }

        return ['base_qty' => $baseQty, 'txn_uom_id' => $uom->id, 'txn_qty' => $qty, 'factor' => $factor];
    }
}
