<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockLotLink;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Support\StockContext;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The stock operations production relies on (MFG-008), built on InventoryService rather than
 * beside it -- every movement still posts its journal, updates the one balance, and leaves one
 * movement row.
 *
 * What this adds is the set of mandatory controls, each enforced here so no caller can forget one:
 *
 *  1. Available stock excludes stock that is reserved, quarantined, on hold, rejected or expired.
 *  2. A material issue names the production order (or authorised adjustment) it is for.
 *  3. A finished-goods receipt names the production order that made it.
 *  4. Every movement carries a traceable reference: a uuid, a source document, a lot and a bin.
 *  5. Every operation is atomic and safe against a repeated request: the item row is locked, and a
 *     request carrying an idempotency key that has already been posted gets the original movement
 *     back rather than a second one.
 *  6. A posted movement is corrected by reversing it, never by editing it -- the database refuses
 *     the edit.
 *  7. There is one balance per item and warehouse. Positions are its breakdown, written by the
 *     same call.
 */
class StockLedger
{
    public function __construct(private readonly InventoryService $inventory) {}

    // ── What can be used ─────────────────────────────────────────────────────────────────────

    /**
     * Quantity that may be issued: available-status positions, not in an expired lot, less what is
     * reserved for somebody else.
     *
     * Reservations held by $forSource are not subtracted -- stock reserved for this production order
     * is exactly the stock this order may take.
     */
    public function available(
        FinStockItem $item,
        ?int $warehouseId = null,
        ?int $lotId = null,
        ?int $binId = null,
        ?DateTimeInterface $asOf = null,
        ?array $forSource = null,
    ): float {
        $day = Carbon::instance($asOf ?? now())->toDateString();

        $onHand = (float) FinStockPosition::withoutGlobalScopes()
            ->where('fin_stock_positions.item_id', $item->id)
            ->where('fin_stock_positions.status', FinStockPosition::AVAILABLE)
            ->when($warehouseId, fn ($q) => $q->where('fin_stock_positions.warehouse_id', $warehouseId))
            ->when($lotId, fn ($q) => $q->where('fin_stock_positions.lot_id', $lotId))
            ->when($binId, fn ($q) => $q->where('fin_stock_positions.bin_id', $binId))
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'fin_stock_positions.lot_id')
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', $day))
            ->sum('fin_stock_positions.qty');

        $reserved = (float) FinStockReservation::withoutGlobalScopes()
            ->where('item_id', $item->id)
            ->where('status', FinStockReservation::OPEN)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            // A lot-specific question subtracts only reservations of that lot, or none-lot ones.
            ->when($lotId, fn ($q) => $q->where(fn ($r) => $r->whereNull('lot_id')->orWhere('lot_id', $lotId)))
            ->when($forSource, fn ($q) => $q->where(fn ($r) => $r
                ->where('source_type', '!=', $forSource[0])->orWhere('source_id', '!=', $forSource[1])))
            ->selectRaw('COALESCE(SUM(qty - qty_consumed), 0) AS remaining')
            ->value('remaining');

        return round(max(0.0, $onHand - $reserved), 4);
    }

    /**
     * Where an item's stock is and whether it can be used -- one row per position with stock in it.
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(FinStockItem $item, ?int $warehouseId = null): array
    {
        return FinStockPosition::withoutGlobalScopes()
            ->with(['lot', 'bin', 'warehouse'])
            ->where('item_id', $item->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('qty', '!=', 0)
            ->orderBy('warehouse_id')->orderBy('status')
            ->get()
            ->map(fn (FinStockPosition $p) => [
                'warehouse_id' => $p->warehouse_id,
                'warehouse' => $p->warehouse?->name,
                'bin_id' => $p->bin_id,
                'bin' => $p->bin?->code,
                'lot_id' => $p->lot_id,
                'lot' => $p->lot?->lot_number,
                'expires_on' => $p->lot?->expires_on?->toDateString(),
                'is_expired' => (bool) $p->lot?->isExpiredOn(),
                'status' => $p->status,
                'qty' => (float) $p->qty,
                // The one thing a picker needs: can I take this?
                'usable' => $p->status === FinStockPosition::AVAILABLE && ! $p->lot?->isExpiredOn(),
            ])->all();
    }

    /**
     * What to pick to issue a quantity, first-expiring first (FEFO), from usable stock only.
     *
     * A lot that expires sooner is used sooner; stock with no expiry is used last. Throws when the
     * usable stock cannot cover the quantity, rather than returning a short pick somebody might
     * post without noticing.
     *
     * @return list<array{lot_id: ?int, bin_id: ?int, qty: float}>
     */
    public function pick(FinStockItem $item, int $warehouseId, float $qty, ?array $forSource = null): array
    {
        $day = now()->toDateString();

        $positions = FinStockPosition::withoutGlobalScopes()
            ->select('fin_stock_positions.*')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'fin_stock_positions.lot_id')
            ->where('fin_stock_positions.item_id', $item->id)
            ->where('fin_stock_positions.warehouse_id', $warehouseId)
            ->where('fin_stock_positions.status', FinStockPosition::AVAILABLE)
            ->where('fin_stock_positions.qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', $day))
            ->orderByRaw('l.expires_on ASC NULLS LAST')
            ->orderBy('fin_stock_positions.id')
            ->get();

        // Lot-specific reservations held for others are subtracted from that lot before picking.
        $heldElsewhere = FinStockReservation::withoutGlobalScopes()
            ->where('item_id', $item->id)->where('warehouse_id', $warehouseId)
            ->where('status', FinStockReservation::OPEN)->whereNotNull('lot_id')
            ->when($forSource, fn ($q) => $q->where(fn ($r) => $r
                ->where('source_type', '!=', $forSource[0])->orWhere('source_id', '!=', $forSource[1])))
            ->get()->groupBy('lot_id')->map(fn ($g) => $g->sum(fn ($r) => $r->remaining()));

        if ($this->available($item, $warehouseId, null, null, null, $forSource) + 0.0001 < $qty) {
            throw new PostingException(sprintf(
                'Only %s of %s is usable in that warehouse; %s is needed.',
                $this->num($this->available($item, $warehouseId, null, null, null, $forSource)), $item->name, $this->num($qty),
            ));
        }

        $left = $qty;
        $picks = [];

        foreach ($positions as $p) {
            $free = (float) $p->qty - (float) ($heldElsewhere[$p->lot_id] ?? 0);
            if ($free <= 0) {
                continue;
            }
            $take = min($left, $free);
            $picks[] = ['lot_id' => $p->lot_id, 'bin_id' => $p->bin_id, 'qty' => round($take, 4)];
            $left = round($left - $take, 4);
            if ($left <= 0) {
                break;
            }
        }

        return $picks;
    }

    // ── Lots ─────────────────────────────────────────────────────────────────────────────────

    /** Find a lot by number, or record a new one. A lot number is unique per item per tenant. */
    public function lot(FinStockItem $item, string $number, array $attributes = []): FinStockLot
    {
        return FinStockLot::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $item->company_id, 'item_id' => $item->id, 'lot_number' => trim($number)],
            ['entity_id' => $item->entity_id] + $attributes,
        );
    }

    // ── Movements ────────────────────────────────────────────────────────────────────────────

    /**
     * Receive purchased or returned stock. Goes to quarantine when $status says so -- incoming
     * inspection (MFG-009) holds material until it is accepted.
     *
     * Receiving into an expired lot is allowed: it may be a return that has to be recorded. It lands
     * where nobody can issue it, because availability excludes expired lots.
     */
    public function receive(FinStockItem $item, float $qty, float $unitCost, FinWarehouse $warehouse, array $options = []): FinStockMovement
    {
        return $this->guarded($item, $options['idempotency_key'] ?? null, function () use ($item, $qty, $unitCost, $warehouse, $options) {
            return $this->inventory->receive(
                $item, $qty, $unitCost, $options['date'] ?? null, $options['contra'] ?? null,
                $options['reference'] ?? null, $warehouse, $this->context($options),
            );
        });
    }

    /**
     * Goods a customer sends back: Dr Inventory / Cr Cost of sales, at the cost they left at
     * (WMS-080). Its own movement type, so a recalled or blocked item can still be taken back --
     * getting recalled stock out of customers' hands is the point -- and so returns are counted
     * apart from purchases. The revenue side is Finance's credit note, which touches no stock.
     */
    public function receiveReturn(FinStockItem $item, float $qty, float $unitCost, FinWarehouse $warehouse, array $options = []): FinStockMovement
    {
        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->receive(
            $item, $qty, $unitCost, $options['date'] ?? null, $options['contra'] ?? config('finance.cogs_account'),
            $options['reference'] ?? null, $warehouse, $this->context($options), 'cust_return',
        ));
    }

    /**
     * Issue material to a production order: Dr Work in Progress / Cr Inventory (control 2).
     *
     * Consumes this order's own reservation first; refuses stock that is quarantined, rejected,
     * expired, or reserved for anybody else.
     */
    public function issueToProduction(FinStockItem $item, float $qty, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A material issue must name the production order or authorised adjustment it is for.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, function () use ($item, $qty, $warehouse, $options) {
            $source = [$options['source_type'], (int) $options['source_id']];
            $lotId = $options['lot_id'] ?? null;

            $this->assertNotExpired($lotId);

            $usable = $this->available($item, $warehouse->id, $lotId, $options['bin_id'] ?? null, null, $source);
            if ($usable + 0.0001 < $qty) {
                throw new PostingException(sprintf(
                    'Only %s of %s may be issued from there -- the rest is reserved, quarantined, '.
                    'rejected or expired. %s was requested.',
                    $this->num($usable), $item->name, $this->num($qty),
                ));
            }

            $movement = $this->inventory->issue(
                $item, $qty, $options['date'] ?? null, $options['reference'] ?? null, $warehouse,
                $this->inventory->accountIdFor(config('finance.wip_account', '1310')),
                $this->context($options), 'mfg_issue',
            );

            $this->consumeReservation($item, $warehouse->id, $lotId, $source, $qty);

            return $movement;
        });
    }

    /**
     * Stock consumed by a cost object that is not a production order — fertiliser on a field, a
     * vaccine on a herd, diesel in a tractor (Mkhaya Agri). Dr the given expense account (cost of
     * sales when none is given) / Cr Inventory, at the item's cost, with the analysis dimensions on
     * the expense line so the cost lands on the farm, field and crop it was used on.
     *
     * The same controls as a production issue: the source must be named, only usable stock may
     * leave, and a repeated request with the same idempotency key returns the original movement.
     *
     * Options: source_type, source_id (required); idempotency_key, date, reference, lot_id, bin_id,
     * expense_account (code), dimensions (array).
     */
    public function issueTo(FinStockItem $item, float $qty, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A stock issue must name what the stock was used for.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, function () use ($item, $qty, $warehouse, $options) {
            $source = [$options['source_type'], (int) $options['source_id']];
            $lotId = $options['lot_id'] ?? null;

            $this->assertNotExpired($lotId);

            $usable = $this->available($item, $warehouse->id, $lotId, $options['bin_id'] ?? null, null, $source);
            if ($usable + 0.0001 < $qty) {
                throw new PostingException(sprintf(
                    'Only %s of %s is available in %s -- the rest is reserved, quarantined or expired. %s was requested.',
                    $this->num($usable), $item->name, $warehouse->name, $this->num($qty),
                ));
            }

            $movement = $this->inventory->issue(
                $item, $qty, $options['date'] ?? null, $options['reference'] ?? null, $warehouse,
                isset($options['expense_account']) ? $this->inventory->accountIdFor($options['expense_account']) : null,
                $this->context($options), 'issue',
            );

            $this->consumeReservation($item, $warehouse->id, $lotId, $source, $qty);

            return $movement;
        });
    }

    /**
     * Unused material back from production into stock: Dr Inventory / Cr Work in Progress, at the
     * cost it left at.
     */
    public function returnFromProduction(FinStockItem $item, float $qty, float $unitCost, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A material return must name the production order it comes back from.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->receive(
            $item, $qty, $unitCost, $options['date'] ?? null, config('finance.wip_account', '1310'),
            $options['reference'] ?? null, $warehouse, $this->context($options), 'mfg_return',
        ));
    }

    /**
     * Finished goods off the line: Dr Inventory / Cr Work in Progress (control 3). Usually into
     * quarantine first, when the quality plan requires inspection before putaway.
     */
    public function receiveFromProduction(FinStockItem $item, float $qty, float $unitCost, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A finished-goods receipt must name the production order that made it.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->receive(
            $item, $qty, $unitCost, $options['date'] ?? null, config('finance.wip_account', '1310'),
            $options['reference'] ?? null, $warehouse, $this->context($options), 'mfg_receipt',
        ));
    }

    /** Write stock off as scrap: Dr Scrap & Spoilage / Cr Inventory, from whatever status it is in. */
    public function scrap(FinStockItem $item, float $qty, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'Scrap must name the order, inspection or adjustment that authorised it.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->issue(
            $item, $qty, $options['date'] ?? null, $options['reference'] ?? 'Scrap', $warehouse,
            $this->inventory->accountIdFor(config('finance.scrap_account', '5610')),
            $this->context($options), 'scrap',
        ));
    }

    /**
     * Split part of a lot into a new lot (MFG-010) -- a pallet broken down, a drum decanted. The new
     * lot inherits the parent's dates and supplier, so it is the same material with a new label,
     * and the link records where it came from.
     */
    public function splitLot(FinStockItem $item, FinWarehouse $warehouse, FinStockLot $parent, float $qty, string $newNumber, array $options = []): FinStockLot
    {
        if ((int) $parent->item_id !== (int) $item->id) {
            throw new PostingException('That lot is not a lot of this item.');
        }
        $this->assertNotExpired($parent->id);

        return DB::transaction(function () use ($item, $warehouse, $parent, $qty, $newNumber, $options) {
            if (FinStockLot::withoutGlobalScopes()->where('company_id', $item->company_id)->where('item_id', $item->id)
                ->where('lot_number', trim($newNumber))->exists()) {
                throw new PostingException("Lot {$newNumber} already exists for this item; split into a new lot number.");
            }

            $child = $this->lot($item, $newNumber, [
                'origin' => 'SPLIT', 'manufactured_on' => $parent->manufactured_on, 'expires_on' => $parent->expires_on,
                'supplier_id' => $parent->supplier_id, 'supplier_lot_number' => $parent->supplier_lot_number,
                'source_type' => $parent->source_type, 'source_id' => $parent->source_id,
            ]);

            $this->relink($item, $warehouse, $parent, $child, $qty, FinStockLotLink::SPLIT, $options);

            return $child;
        });
    }

    /**
     * Combine several lots into one new lot (MFG-010) -- blended batches, part pallets made up.
     * Every parent is linked with the quantity it contributed, so a recall of any one of them
     * reaches the merged lot. A merged lot expires when its earliest parent does.
     *
     * @param  array<int, float>  $parts  lot id => quantity
     */
    public function mergeLots(FinStockItem $item, FinWarehouse $warehouse, array $parts, string $newNumber, array $options = []): FinStockLot
    {
        if (count($parts) < 2) {
            throw new PostingException('A merge needs at least two lots.');
        }

        $parents = FinStockLot::withoutGlobalScopes()->whereIn('id', array_keys($parts))->get()->keyBy('id');
        if ($parents->count() !== count($parts) || $parents->contains(fn ($l) => (int) $l->item_id !== (int) $item->id)) {
            throw new PostingException('Every lot in a merge must be a lot of this item.');
        }
        foreach ($parents as $p) {
            $this->assertNotExpired($p->id);
        }

        return DB::transaction(function () use ($item, $warehouse, $parts, $parents, $newNumber, $options) {
            if (FinStockLot::withoutGlobalScopes()->where('company_id', $item->company_id)->where('item_id', $item->id)
                ->where('lot_number', trim($newNumber))->exists()) {
                throw new PostingException("Lot {$newNumber} already exists for this item; merge into a new lot number.");
            }

            $child = $this->lot($item, $newNumber, [
                'origin' => 'MERGE',
                'expires_on' => $parents->pluck('expires_on')->filter()->min(),
                'manufactured_on' => $parents->pluck('manufactured_on')->filter()->min(),
            ]);

            foreach ($parts as $lotId => $qty) {
                $this->relink($item, $warehouse, $parents[$lotId], $child, (float) $qty, FinStockLotLink::MERGE, $options);
            }

            return $child;
        });
    }

    /**
     * Ship stock to a customer (WMS-065): Dr Cost of Sales / Cr Inventory, consuming the order's
     * own reservation first. For a warehouse WHMI runs, this -- not the invoice -- is where sold
     * stock leaves the books (WHMI decision 3).
     */
    public function dispatchToCustomer(FinStockItem $item, float $qty, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A dispatch must name the order it ships.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, function () use ($item, $qty, $warehouse, $options) {
            $source = [$options['source_type'], (int) $options['source_id']];
            $this->consumeReservation($item, $warehouse->id, $options['lot_id'] ?? null, $source, $qty);

            return $this->inventory->issue(
                $item, $qty, $options['date'] ?? null, $options['reference'] ?? null, $warehouse, null,
                $this->context($options), 'dispatch',
            );
        });
    }

    /**
     * Send stock back to the supplier (WMS-035), movement type `to_supplier` (the type column is 12
     * characters): Dr Goods Received Not Invoiced / Cr Inventory, at
     * the item's cost. Usually from rejected or quarantined stock, which is exactly what may not be
     * issued any other way, so the status to take it from is named rather than assumed.
     */
    public function returnToSupplier(FinStockItem $item, float $qty, FinWarehouse $warehouse, array $options): FinStockMovement
    {
        $this->requireSource($options, 'A supplier return must name the return document it belongs to.');

        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->issue(
            $item, $qty, $options['date'] ?? null, $options['reference'] ?? null, $warehouse,
            $this->inventory->accountIdFor(config('finance.grni_account', '2150')),
            $this->context($options), 'to_supplier',
        ));
    }

    /** Quarantine, release, hold or reject a quantity in place (MFG-009 dispositions). */
    public function changeStatus(FinStockItem $item, FinWarehouse $warehouse, float $qty, string $from, string $to, array $options = []): FinStockMovement
    {
        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->restatus(
            $item, $warehouse, $qty, $this->context(['status' => $from] + $options), $to,
            $options['reason'] ?? null, $options['date'] ?? null,
        ));
    }

    /** Move stock between warehouses or between bins of one warehouse. Value stays in inventory. */
    public function transfer(FinStockItem $item, FinWarehouse $from, FinWarehouse $to, float $qty, array $options = []): FinStockMovement
    {
        return $this->guarded($item, $options['idempotency_key'] ?? null, function () use ($item, $from, $to, $qty, $options) {
            $this->assertNotExpired($options['lot_id'] ?? null);

            $this->inventory->transfer($item, $from, $to, $qty, $options['date'] ?? null, $options['reference'] ?? null, $this->context($options));

            return FinStockMovement::withoutGlobalScopes()
                ->where('item_id', $item->id)->where('type', 'transfer')->where('qty', '<', 0)
                ->orderByDesc('id')->firstOrFail();
        });
    }

    /**
     * An authorised correction of quantity -- a stock count, a write-off. Requires a reason, because
     * an adjustment nobody can explain is the hole every stock loss walks through.
     */
    public function adjust(FinStockItem $item, FinWarehouse $warehouse, float $qtyDelta, float $unitCost, array $options): FinStockMovement
    {
        if (blank($options['reason'] ?? null)) {
            throw new PostingException('A stock adjustment must say why it was made.');
        }

        return $this->guarded($item, $options['idempotency_key'] ?? null, fn () => $this->inventory->adjust(
            $item, $qtyDelta, $unitCost, $options['reason'], $options['date'] ?? null, $options['contra'] ?? null,
            $warehouse, $this->context(['source_type' => $options['source_type'] ?? 'ADJUSTMENT'] + $options),
        ));
    }

    /**
     * Cycle count: record what is physically there, and post the difference as an adjustment.
     * Returns null when the count agrees with the books -- nothing to post is not an error.
     */
    public function count(FinStockItem $item, FinWarehouse $warehouse, float $counted, array $options): ?FinStockMovement
    {
        $status = $options['status'] ?? FinStockPosition::AVAILABLE;

        $booked = (float) FinStockPosition::withoutGlobalScopes()
            ->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->where('status', $status)
            ->when(($options['bin_id'] ?? null) === null, fn ($q) => $q->whereNull('bin_id'), fn ($q) => $q->where('bin_id', $options['bin_id']))
            ->when(($options['lot_id'] ?? null) === null, fn ($q) => $q->whereNull('lot_id'), fn ($q) => $q->where('lot_id', $options['lot_id']))
            ->value('qty');

        $delta = round($counted - $booked, 4);

        if (abs($delta) < 0.0001) {
            return null;
        }

        return $this->adjust($item, $warehouse, $delta, ((float) $item->avg_cost ?: (float) $item->standard_cost), [
            'reason' => $options['reason'] ?? sprintf('Cycle count: counted %s, booked %s', $this->num($counted), $this->num($booked)),
            'source_type' => 'CYCLE_COUNT',
        ] + $options);
    }

    /** Undo a posted movement with a new one (control 6). */
    public function reverse(FinStockMovement $movement, string $reason, ?string $idempotencyKey = null): FinStockMovement
    {
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($movement->item_id);

        return $this->guarded($item, $idempotencyKey, fn () => $this->inventory->reverse($movement, $reason, $idempotencyKey));
    }

    // ── Reservations ─────────────────────────────────────────────────────────────────────────

    /**
     * Hold stock for a source. Re-reserving for the same source tops its reservation up to the new
     * quantity rather than adding a second one (MFG-005: no double-counting).
     */
    public function reserve(FinStockItem $item, FinWarehouse $warehouse, float $qty, string $sourceType, int $sourceId, ?int $lotId = null): FinStockReservation
    {
        return DB::transaction(function () use ($item, $warehouse, $qty, $sourceType, $sourceId, $lotId) {
            FinStockItem::withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->first();

            $existing = FinStockReservation::withoutGlobalScopes()
                ->where('source_type', $sourceType)->where('source_id', $sourceId)
                ->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)
                ->where('status', FinStockReservation::OPEN)
                ->when($lotId === null, fn ($q) => $q->whereNull('lot_id'), fn ($q) => $q->where('lot_id', $lotId))
                ->lockForUpdate()->first();

            $already = $existing ? $existing->remaining() : 0.0;
            $extra = round($qty - $already, 4);

            if ($extra > 0) {
                $free = $this->available($item, $warehouse->id, $lotId);
                if ($free + 0.0001 < $extra) {
                    throw new PostingException(sprintf(
                        'Only %s of %s is free to reserve; %s more is needed.',
                        $this->num($free), $item->name, $this->num($extra),
                    ));
                }
            }

            if ($existing) {
                $existing->qty = round((float) $existing->qty_consumed + $qty, 4);
                $existing->save();

                return $existing;
            }

            return FinStockReservation::create([
                'company_id' => $item->company_id, 'entity_id' => $item->entity_id,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'lot_id' => $lotId,
                'qty' => $qty, 'qty_consumed' => 0, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'status' => FinStockReservation::OPEN, 'created_by' => Auth::id(),
            ]);
        });
    }

    /** Let go of everything a source still holds -- a cancelled order, a closed one with leftovers. */
    public function releaseFor(string $sourceType, int $sourceId, string $reason): int
    {
        return FinStockReservation::withoutGlobalScopes()
            ->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->where('status', FinStockReservation::OPEN)
            ->update(['status' => FinStockReservation::RELEASED, 'release_reason' => $reason, 'released_at' => now(), 'updated_at' => now()]);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /**
     * Lock, then check for a previous attempt, then do the work -- all in one transaction.
     *
     * The order matters. Checking for the key before taking the lock lets two simultaneous retries
     * both see "not yet posted" and both post. Locking the item row first means the second one waits,
     * then finds the first one's movement.
     */
    private function guarded(FinStockItem $item, ?string $idempotencyKey, callable $work): FinStockMovement
    {
        return DB::transaction(function () use ($item, $idempotencyKey, $work) {
            $locked = FinStockItem::withoutGlobalScopes()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $item->setRawAttributes($locked->getAttributes(), true);

            if ($idempotencyKey !== null) {
                $previous = FinStockMovement::withoutGlobalScopes()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where(fn ($q) => $item->company_id === null
                        ? $q->whereNull('company_id') : $q->where('company_id', $item->company_id))
                    ->first();

                if ($previous) {
                    return $previous;
                }
            }

            return $work();
        });
    }

    private function relink(FinStockItem $item, FinWarehouse $warehouse, FinStockLot $parent, FinStockLot $child, float $qty, string $kind, array $options): void
    {
        // No idempotency key on the parts: a merge writes one movement per parent, and the key is unique.
        unset($options['idempotency_key']);
        $movement = $this->inventory->relot($item, $warehouse, $qty, $this->context(['lot_id' => $parent->id] + $options), $child->id,
            $options['reason'] ?? strtolower($kind)." {$parent->lot_number} -> {$child->lot_number}", $options['date'] ?? null);

        FinStockLotLink::create([
            'company_id' => $item->company_id, 'entity_id' => $item->entity_id,
            'parent_lot_id' => $parent->id, 'child_lot_id' => $child->id, 'kind' => $kind, 'qty' => $qty,
            'movement_id' => $movement->id, 'created_by' => Auth::id(),
        ]);
    }

    private function context(array $options): StockContext
    {
        return new StockContext(
            binId: $options['bin_id'] ?? null,
            lotId: $options['lot_id'] ?? null,
            status: $options['status'] ?? FinStockPosition::AVAILABLE,
            sourceType: $options['source_type'] ?? null,
            sourceId: isset($options['source_id']) ? (int) $options['source_id'] : null,
            idempotencyKey: $options['idempotency_key'] ?? null,
            toBinId: $options['to_bin_id'] ?? null,
            toStatus: $options['to_status'] ?? null,
            strict: true,
            meta: array_intersect_key($options, array_flip(StockContext::META_COLUMNS)),
            dimensions: $options['dimensions'] ?? [],
        );
    }

    private function consumeReservation(FinStockItem $item, int $warehouseId, ?int $lotId, array $source, float $qty): void
    {
        $left = $qty;

        $reservations = FinStockReservation::withoutGlobalScopes()
            ->where('item_id', $item->id)->where('warehouse_id', $warehouseId)
            ->where('source_type', $source[0])->where('source_id', $source[1])
            ->where('status', FinStockReservation::OPEN)
            // The lot-specific hold first, then the general one.
            ->orderByRaw('CASE WHEN lot_id = ? THEN 0 WHEN lot_id IS NULL THEN 1 ELSE 2 END', [$lotId ?? 0])
            ->lockForUpdate()->get();

        foreach ($reservations as $r) {
            if ($r->lot_id !== null && $r->lot_id !== $lotId) {
                continue;
            }
            $take = min($left, $r->remaining());
            if ($take <= 0) {
                continue;
            }
            $r->qty_consumed = round((float) $r->qty_consumed + $take, 4);
            if ($r->remaining() <= 0.0001) {
                $r->status = FinStockReservation::CONSUMED;
            }
            $r->save();
            $left = round($left - $take, 4);
            if ($left <= 0) {
                break;
            }
        }
    }

    private function requireSource(array $options, string $message): void
    {
        if (blank($options['source_type'] ?? null) || blank($options['source_id'] ?? null)) {
            throw new PostingException($message);
        }
    }

    private function assertNotExpired(?int $lotId): void
    {
        if ($lotId === null) {
            return;
        }

        $lot = FinStockLot::withoutGlobalScopes()->find($lotId);

        if ($lot?->isExpiredOn()) {
            throw new PostingException("Lot {$lot->lot_number} expired on {$lot->expires_on->toDateString()} and cannot be used.");
        }
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
