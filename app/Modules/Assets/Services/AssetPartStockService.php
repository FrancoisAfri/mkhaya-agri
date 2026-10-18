<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetPart;
use App\Modules\Assets\Models\AssetPartTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Atomic stock movements for spare parts (MRO inventory).
 *
 * Every movement runs inside a DB transaction and re-reads the part with
 * lockForUpdate() so concurrent receipts/issues can't race on quantity_on_hand.
 * Each movement writes an asset_part_transactions row carrying the resulting
 * balance_after.
 *
 * $meta may include: work_order_id, reference, performed_by (defaults to the
 * current user), notes, unit_cost, occurred_at.
 */
class AssetPartStockService
{
    /**
     * Receive stock into inventory (increase on-hand).
     */
    public function receive(AssetPart $part, float $qty, array $meta = []): AssetPartTransaction
    {
        abort_if($qty <= 0, 422, 'Receipt quantity must be greater than zero.');

        return DB::transaction(function () use ($part, $qty, $meta) {
            $locked  = AssetPart::query()->lockForUpdate()->findOrFail($part->getKey());
            $balance = round((float) $locked->quantity_on_hand + $qty, 2);

            $locked->quantity_on_hand = $balance;
            // Keep the standing unit cost fresh when a receipt states one.
            if (array_key_exists('unit_cost', $meta) && $meta['unit_cost'] !== null && $meta['unit_cost'] !== '') {
                $locked->unit_cost = (float) $meta['unit_cost'];
            }
            $locked->save();

            $part->setRawAttributes($locked->getAttributes());

            return $this->record($locked, 'receipt', $qty, $balance, $meta);
        });
    }

    /**
     * Issue stock out of inventory (decrease on-hand). Blocks going negative.
     */
    public function issue(AssetPart $part, float $qty, array $meta = []): AssetPartTransaction
    {
        abort_if($qty <= 0, 422, 'Issue quantity must be greater than zero.');

        return DB::transaction(function () use ($part, $qty, $meta) {
            $locked = AssetPart::query()->lockForUpdate()->findOrFail($part->getKey());

            abort_if(
                $qty > (float) $locked->quantity_on_hand,
                422,
                "Insufficient stock: {$locked->quantity_on_hand} on hand, cannot issue {$qty}.",
            );

            $balance = round((float) $locked->quantity_on_hand - $qty, 2);
            $locked->quantity_on_hand = $balance;
            $locked->save();

            $part->setRawAttributes($locked->getAttributes());

            return $this->record($locked, 'issue', $qty, $balance, $meta);
        });
    }

    /**
     * Correct the on-hand figure to an absolute count (a stock-take/adjustment).
     *
     * $newQty is the counted, absolute on-hand quantity. The recorded movement
     * magnitude is the delta between old and new; negative deltas that would
     * push stock below zero are blocked.
     */
    public function adjust(AssetPart $part, float $newQty, array $meta = []): AssetPartTransaction
    {
        abort_if($newQty < 0, 422, 'Adjusted quantity cannot be negative.');

        return DB::transaction(function () use ($part, $newQty, $meta) {
            $locked = AssetPart::query()->lockForUpdate()->findOrFail($part->getKey());

            $delta   = round($newQty - (float) $locked->quantity_on_hand, 2);
            $balance = round($newQty, 2);

            $locked->quantity_on_hand = $balance;
            $locked->save();

            $part->setRawAttributes($locked->getAttributes());

            // Magnitude of the correction; direction lives in the sign of the delta note.
            return $this->record($locked, 'adjustment', abs($delta), $balance, $meta);
        });
    }

    /**
     * Persist the movement row.
     */
    private function record(AssetPart $part, string $type, float $qty, float $balance, array $meta): AssetPartTransaction
    {
        $occurredAt = $meta['occurred_at'] ?? null;

        return AssetPartTransaction::create([
            'company_id'    => $part->company_id,
            'part_id'       => $part->getKey(),
            'type'          => $type,
            'quantity'      => round($qty, 2),
            'balance_after' => round($balance, 2),
            'unit_cost'     => $meta['unit_cost'] ?? $part->unit_cost,
            'work_order_id' => $meta['work_order_id'] ?? null,
            'reference'     => $meta['reference'] ?? null,
            'performed_by'  => $meta['performed_by'] ?? Auth::id(),
            'notes'         => $meta['notes'] ?? null,
            'occurred_at'   => $occurredAt ? Carbon::parse($occurredAt) : now(),
        ]);
    }
}
