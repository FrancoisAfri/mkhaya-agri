<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\UomConversion;
use DomainException;

/**
 * MFG-001.05 — "with valid conversions", which is the part that matters.
 *
 * Two units of the same dimension always convert, because each one states its size relative to its
 * dimension's base unit. Nothing has to be configured for kg→g or metres→mm.
 *
 * Across dimensions it refuses. kg→litres has no answer that does not depend on what the substance
 * is, and a module that guesses one will quietly mis-state a requirement by a factor of the
 * density. Where a cross-dimension or non-linear relationship genuinely exists it belongs to an
 * item — a pack of twelve, a 210-litre drum — and is looked up per item.
 */
class UomConverter
{
    /** @var array<string, float> */
    private array $itemFactors = [];

    /**
     * Convert a quantity, optionally in the context of one stock item.
     *
     * The item is what makes "3 packs" into "36 units"; without it a pack is not a unit of
     * anything in particular.
     */
    public function convert(float $quantity, Uom $from, Uom $to, ?int $stockItemId = null): float
    {
        if ($from->id === $to->id) {
            return $quantity;
        }

        /*
         * An item's own conversion is more specific than anything derived from the dimension, so
         * it is asked first. It is also the only way a container converts at all.
         */
        $factor = $stockItemId === null ? null : $this->itemFactor($stockItemId, $from, $to);

        if ($factor !== null) {
            return $quantity * $factor;
        }

        if ($from->isCompatibleWith($to)) {
            return $quantity * ((float) $from->factor_to_base / (float) $to->factor_to_base);
        }

        throw new DomainException(
            "Cannot convert {$from->code} ({$from->dimension}) to {$to->code} ({$to->dimension}): "
            .'the two measure different things, and no conversion is recorded for this item. '
            .'Record one against the item if a fixed relationship exists.'
        );
    }

    /** Quantity expressed in the dimension's base unit — what totals and comparisons use. */
    public function toBase(float $quantity, Uom $from): float
    {
        return $quantity * (float) $from->factor_to_base;
    }

    /** Can these two be converted at all, for this item? Asked before offering a unit in a form. */
    public function canConvert(Uom $from, Uom $to, ?int $stockItemId = null): bool
    {
        if ($from->id === $to->id) {
            return true;
        }

        if ($stockItemId !== null && $this->itemFactor($stockItemId, $from, $to) !== null) {
            return true;
        }

        return $from->isCompatibleWith($to);
    }

    /** Rounded for display in the target unit, which decides its own precision. */
    public function convertForDisplay(float $quantity, Uom $from, Uom $to, ?int $stockItemId = null): float
    {
        return round($this->convert($quantity, $from, $to, $stockItemId), (int) $to->decimal_places);
    }

    /**
     * An item-specific factor, in either direction.
     *
     * A conversion recorded as 1 pack = 12 units answers units→packs as well, by inversion. Making
     * somebody record both rows is how the two end up disagreeing.
     */
    private function itemFactor(int $stockItemId, Uom $from, Uom $to): ?float
    {
        $key = "{$stockItemId}:{$from->id}:{$to->id}";

        if (array_key_exists($key, $this->itemFactors)) {
            return $this->itemFactors[$key];
        }

        $forward = UomConversion::query()
            ->where('stock_item_id', $stockItemId)
            ->where('from_uom_id', $from->id)
            ->where('to_uom_id', $to->id)
            ->value('factor');

        if ($forward !== null) {
            return $this->itemFactors[$key] = (float) $forward;
        }

        $reverse = UomConversion::query()
            ->where('stock_item_id', $stockItemId)
            ->where('from_uom_id', $to->id)
            ->where('to_uom_id', $from->id)
            ->value('factor');

        if ($reverse !== null && (float) $reverse !== 0.0) {
            return $this->itemFactors[$key] = 1 / (float) $reverse;
        }

        return $this->itemFactors[$key] = null;
    }
}
