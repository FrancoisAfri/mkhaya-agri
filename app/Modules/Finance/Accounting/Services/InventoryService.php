<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinStockBalance;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLayer;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Support\StockContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inventory engine (Phase 5.2). Receipts add stock (Dr Inventory / Cr supplier),
 * issues relieve stock at cost (Dr COGS / Cr Inventory) using FIFO, weighted
 * average or standard cost. Every movement posts to the ledger.
 */
class InventoryService
{
    /** True only while sell() writes its movement; see the comment there. */
    private bool $relievingSale = false;

    public function __construct(private Ledger $ledger, private \App\Modules\Tenancy\Services\CurrentCompany $currentCompany)
    {
    }

    public function createItem(array $data): FinStockItem
    {
        $data['inventory_account_id'] ??= $this->accountId(config('finance.inventory_account', '1300'));
        $data['cogs_account_id']      ??= $this->accountId(config('finance.cogs_account', '5000'));

        return FinStockItem::create($data);
    }

    /** Receive stock into a warehouse: Dr Inventory / Cr the funding account (AP by default). */
    public function receive(FinStockItem $item, float $qty, float $unitCost, ?string $date = null, ?string $contra = null, ?string $reference = null, ?FinWarehouse $warehouse = null, ?StockContext $context = null, string $type = 'receipt'): FinStockMovement
    {
        if ($qty <= 0) {
            throw new PostingException('Receipt quantity must be greater than zero.');
        }
        $date  = Carbon::parse($date ?? now());
        // Stock held for somebody else (a 3PL client's goods) is counted, never valued: it is not
        // this company's asset, so it posts no journal and carries no cost (WHMI decision 1).
        $value = $item->isValued() ? round($qty * $unitCost, 2) : 0.0;
        $contra = $contra ?: config('finance.ap_control');
        $warehouseId = $this->warehouseId($item, $warehouse);

        return DB::transaction(function () use ($item, $qty, $unitCost, $date, $value, $contra, $reference, $warehouseId, $context, $type) {
            $journal = ! $item->isValued() ? null : $this->ledger->post(new FinancialEvent(
                sourceModule: 'inventory', eventType: 'stock.receipt',
                debits:  [['account' => $item->inventory_account_id, 'amount' => $value, 'narration' => "Receive {$item->name}"]],
                credits: [['account' => $contra, 'amount' => $value, 'narration' => "Receive {$item->name}"]],
                eventDate: $date, companyId: $item->company_id, sourceRef: "stock_item:{$item->id}",
                description: "Stock receipt — {$item->name}",
            ));

            if ($item->cost_method === 'fifo' && $item->isValued()) {
                FinStockLayer::create(['item_id' => $item->id, 'received_at' => $date, 'qty_remaining' => $qty, 'unit_cost' => $unitCost]);
            }

            $oldQty = (float) $item->qty_on_hand;
            $newQty = $oldQty + $qty;
            $item->update([
                'qty_on_hand' => $newQty,
                'avg_cost'    => $newQty > 0 ? round(((($oldQty * (float) $item->avg_cost) + $value) / $newQty), 4) : 0,
            ]);
            $this->applyBalance($item, $warehouseId, $qty, $context);

            return $this->movement($item, $type, $qty, $value, $date, $reference, $journal?->id, $warehouseId, $context);
        });
    }

    /** Issue stock at cost from a warehouse: Dr COGS / Cr Inventory. Cost depends on the method. */
    /*
     * $debitAccountId: where the issued cost goes. Cost of sales by default -- which is right for a
     * sale and wrong for production, where material becomes work in progress until the order
     * completes. Manufacturing passes the WIP account.
     */
    public function issue(FinStockItem $item, float $qty, ?string $date = null, ?string $reference = null, ?FinWarehouse $warehouse = null, ?int $debitAccountId = null, ?StockContext $context = null, string $type = 'issue'): FinStockMovement
    {
        if ($qty <= 0) {
            throw new PostingException('Issue quantity must be greater than zero.');
        }
        if ($qty > (float) $item->qty_on_hand + 0.0001) {
            throw new PostingException("Not enough stock of {$item->name} (on hand: {$item->qty_on_hand}).");
        }
        $date = Carbon::parse($date ?? now());
        $warehouseId = $this->warehouseId($item, $warehouse);

        if ($context === null) {
            $this->refuseUnusableStock($item, $warehouseId, $qty);
        }

        return DB::transaction(function () use ($item, $qty, $date, $reference, $warehouseId, $debitAccountId, $context, $type) {
            $value = $item->isValued() ? round($this->costOfIssue($item, $qty), 2) : 0.0;

            $journal = ! $item->isValued() ? null : $this->ledger->post(new FinancialEvent(
                sourceModule: 'inventory', eventType: 'stock.issue',
                debits:  [['account' => $debitAccountId ?? $item->cogs_account_id, 'amount' => $value, 'narration' => "Issue {$item->name}"]],
                credits: [['account' => $item->inventory_account_id, 'amount' => $value, 'narration' => "Issue {$item->name}"]],
                eventDate: $date, companyId: $item->company_id, sourceRef: "stock_item:{$item->id}",
                description: "Stock issue — {$item->name}",
            ));

            $item->update(['qty_on_hand' => round((float) $item->qty_on_hand - $qty, 4)]);
            $this->applyBalance($item, $warehouseId, -$qty, $context);

            return $this->movement($item, $type, -$qty, $value, $date, $reference, $journal?->id, $warehouseId, $context);
        });
    }

    /**
     * Relieve stock for a SALE — tolerant of overselling (qty may go negative), so it never blocks
     * an invoice. Costs at the item's method (FIFO layers as far as they go, remainder at avg/standard)
     * and posts Dr COGS / Cr Inventory for the cost. Returns null for a non-positive quantity.
     */
    public function sell(FinStockItem $item, float $qty, ?string $date = null, ?string $reference = null, ?FinWarehouse $warehouse = null): ?FinStockMovement
    {
        if ($qty <= 0) {
            return null;
        }
        $date = Carbon::parse($date ?? now());
        $warehouseId = $this->warehouseId($item, $warehouse);

        return DB::transaction(function () use ($item, $qty, $date, $reference, $warehouseId) {
            $value = $item->isValued() ? round($this->costOfSale($item, $qty), 2) : 0.0;
            $journalId = null;
            if ($value > 0.005) {
                $journal = $this->ledger->post(new FinancialEvent(
                    sourceModule: 'inventory', eventType: 'stock.sale_issue',
                    debits:  [['account' => $item->cogs_account_id, 'amount' => $value, 'narration' => "COGS {$item->name}"]],
                    credits: [['account' => $item->inventory_account_id, 'amount' => $value, 'narration' => "Issue {$item->name}"]],
                    eventDate: $date, companyId: $item->company_id, sourceRef: "stock_item:{$item->id}",
                    description: "Cost of sale — {$item->name}",
                ));
                $journalId = $journal->id;
            }
            $item->update(['qty_on_hand' => round((float) $item->qty_on_hand - $qty, 4)]);
            $this->applyBalance($item, $warehouseId, -$qty);

            // Invoice relief records a sale that has already happened; it never blocks an invoice,
            // so the item's lifecycle status is not asked here (WMS-013 governs new movements).
            $this->relievingSale = true;
            try {
                return $this->movement($item, 'issue', -$qty, $value, $date, $reference, $journalId, $warehouseId);
            } finally {
                $this->relievingSale = false;
            }
        });
    }

    /** Relieve stock for every stock-tracked line on a posted sales invoice (from the default warehouse). */
    public function relieveForSale(FinInvoice $invoice): void
    {
        if ($invoice->direction !== 'receivable' || in_array($invoice->doc_type, ['credit_note', 'writeoff', 'debit_note'], true)) {
            return;
        }
        // WHMI decision 3: a warehouse run by Warehouse Management ships stock out at dispatch,
        // through the fulfilment order. Relieving it again here would take every sale out twice.
        if ($this->defaultWarehouse()->is_wms_managed) {
            Log::info('Invoice stock relief skipped: the warehouse ships through WHMI', ['invoice' => $invoice->id]);

            return;
        }

        $invoice->loadMissing('lines');
        foreach ($invoice->lines as $line) {
            if (! $line->item_id || (float) $line->quantity <= 0) {
                continue;
            }
            $stock = $this->stockItemForFinItem((int) $line->item_id, $invoice->company_id);
            if (! $stock) {
                continue;
            }
            try {
                $this->sell($stock, (float) $line->quantity, optional($invoice->invoice_date)->toDateString(), "invoice:{$invoice->id}");
            } catch (\Throwable $e) {
                Log::warning('Stock relief on sale failed', ['invoice' => $invoice->id, 'item' => $line->item_id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** The stock item behind a sales line's price-list item -- the same match invoice relief uses. */
    public function stockItemForSaleItem(int $finItemId, ?int $companyId): ?FinStockItem
    {
        return $this->stockItemForFinItem($finItemId, $companyId);
    }

    /** The inventory item that backs a price-list item, matched by code within the tenant. */
    private function stockItemForFinItem(int $finItemId, ?int $companyId): ?FinStockItem
    {
        $code = \App\Modules\Finance\Accounting\Models\FinItem::whereKey($finItemId)->value('code');
        if (! $code) {
            return null;
        }

        return FinStockItem::where('code', $code)->where('is_active', true)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->first();
    }

    /**
     * Manually adjust stock up or down (stock-take, write-off, correction). Posts the value against
     * a contra account: increases Dr Inventory / Cr contra; decreases Dr contra / Cr Inventory.
     */
    public function adjust(FinStockItem $item, float $qtyDelta, float $unitCost, ?string $reason = null, ?string $date = null, ?string $contra = null, ?FinWarehouse $warehouse = null, ?StockContext $context = null): FinStockMovement
    {
        if (abs($qtyDelta) < 0.0001) {
            throw new PostingException('Adjustment quantity cannot be zero.');
        }
        $date = Carbon::parse($date ?? now());
        $contraCode = $contra ?: config('finance.stock_adjustment', config('finance.cogs_account', '5000'));
        $warehouseId = $this->warehouseId($item, $warehouse);

        return DB::transaction(function () use ($item, $qtyDelta, $unitCost, $reason, $date, $contraCode, $warehouseId, $context) {
            $value = $item->isValued() ? round(abs($qtyDelta) * $unitCost, 2) : 0.0;
            $journalId = null;
            if ($value > 0.005) {
                $inv = $item->inventory_account_id;
                $contraId = $this->accountId($contraCode);
                $event = $qtyDelta > 0
                    ? new FinancialEvent(sourceModule: 'inventory', eventType: 'stock.adjust',
                        debits: [['account' => $inv, 'amount' => $value, 'narration' => "Adjust {$item->name}"]],
                        credits: [['account' => $contraId, 'amount' => $value, 'narration' => $reason ?: 'Stock adjustment']],
                        eventDate: $date, companyId: $item->company_id, sourceRef: "stock_item:{$item->id}")
                    : new FinancialEvent(sourceModule: 'inventory', eventType: 'stock.adjust',
                        debits: [['account' => $contraId, 'amount' => $value, 'narration' => $reason ?: 'Stock adjustment']],
                        credits: [['account' => $inv, 'amount' => $value, 'narration' => "Adjust {$item->name}"]],
                        eventDate: $date, companyId: $item->company_id, sourceRef: "stock_item:{$item->id}");
                $journalId = $this->ledger->post($event)->id;
            }

            $newQty = round((float) $item->qty_on_hand + $qtyDelta, 4);
            // Keep weighted-average sensible when adjusting up with a fresh cost.
            if ($qtyDelta > 0 && $item->cost_method === 'weighted_avg' && $newQty > 0) {
                $newValue = ((float) $item->qty_on_hand * (float) $item->avg_cost) + ($qtyDelta * $unitCost);
                $item->avg_cost = round($newValue / $newQty, 4);
            }
            $item->qty_on_hand = $newQty;
            $item->save();
            $this->applyBalance($item, $warehouseId, $qtyDelta, $context);

            return $this->movement($item, 'adjustment', $qtyDelta, $qtyDelta > 0 ? $value : -$value, $date, $reason, $journalId, $warehouseId, $context);
        });
    }

    /** The cost of a sale — like costOfIssue but the FIFO remainder falls back to avg/standard cost. */
    private function costOfSale(FinStockItem $item, float $qty): float
    {
        if ($item->cost_method === 'standard') {
            return $qty * (float) $item->standard_cost;
        }
        if ($item->cost_method === 'weighted_avg') {
            return $qty * (float) $item->avg_cost;
        }
        $remaining = $qty;
        $cost = 0.0;
        foreach ($item->layers()->where('qty_remaining', '>', 0)->orderBy('id')->get() as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $layer->qty_remaining);
            $cost += $take * (float) $layer->unit_cost;
            $layer->update(['qty_remaining' => round((float) $layer->qty_remaining - $take, 4)]);
            $remaining -= $take;
        }
        if ($remaining > 0.0001) {
            $fallback = (float) $item->avg_cost ?: (float) $item->standard_cost;
            $cost += $remaining * $fallback;
        }

        return $cost;
    }

    /** The cost of issuing $qty, and (for FIFO) consume the layers. */
    private function costOfIssue(FinStockItem $item, float $qty): float
    {
        if ($item->cost_method === 'standard') {
            return $qty * (float) $item->standard_cost;
        }
        if ($item->cost_method === 'weighted_avg') {
            return $qty * (float) $item->avg_cost;
        }

        // FIFO — consume oldest layers first.
        $remaining = $qty;
        $cost = 0.0;
        foreach ($item->layers()->where('qty_remaining', '>', 0)->orderBy('id')->get() as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $layer->qty_remaining);
            $cost += $take * (float) $layer->unit_cost;
            $layer->update(['qty_remaining' => round((float) $layer->qty_remaining - $take, 4)]);
            $remaining -= $take;
        }

        return $cost;
    }

    public function valuation(?int $companyId = null): array
    {
        // Per-item, per-warehouse quantities in one query, keyed by item.
        $balances = FinStockBalance::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get(['item_id', 'warehouse_id', 'qty'])
            ->groupBy('item_id');

        return FinStockItem::active()->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('code')->get()
            ->map(fn (FinStockItem $i) => [
                'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'method' => $i->cost_method,
                'qty_on_hand' => (float) $i->qty_on_hand, 'avg_cost' => (float) $i->avg_cost,
                'value' => $i->stockValue(),
                'reorder_level' => (float) ($i->reorder_level ?? 0),
                'low_stock' => (float) ($i->reorder_level ?? 0) > 0 && (float) $i->qty_on_hand <= (float) $i->reorder_level,
                'balances' => ($balances[$i->id] ?? collect())
                    ->filter(fn ($b) => abs((float) $b->qty) > 0.00001)
                    ->map(fn ($b) => ['warehouse_id' => (int) $b->warehouse_id, 'qty' => (float) $b->qty])
                    ->values()->all(),
            ])->values()->all();
    }

    /** Active warehouses for the current tenant (default first). */
    public function warehouses(?int $companyId = null): array
    {
        $cid = $companyId ?? $this->currentCompany->id() ?? Auth::user()?->company_id;

        return FinWarehouse::query()->active()
            ->when($cid, fn ($q) => $q->where('company_id', $cid))
            ->orderByDesc('is_default')->orderBy('name')
            ->get(['id', 'code', 'name', 'is_default'])
            ->map(fn (FinWarehouse $w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'is_default' => $w->is_default])
            ->all();
    }

    private function movement(FinStockItem $item, string $type, float $qty, float $value, Carbon $date, ?string $ref, ?int $journalId, ?int $warehouseId = null, ?StockContext $context = null): FinStockMovement
    {
        // WMS-013: the item's lifecycle status. Every movement passes here, inside the posting
        // transaction, so a refusal undoes the balance change made just before it.
        if (! $this->relievingSale && ($refusal = $item->refusalFor($type, $context?->toStatus)) !== null) {
            throw new PostingException($refusal);
        }

        return FinStockMovement::create(($context?->movementMeta() ?? []) + [
            // The item's tenant and business, stated rather than left to the session: a movement
            // written from a job or a command would otherwise carry no company at all.
            'company_id' => $item->company_id, 'entity_id' => $item->entity_id,
            'item_id' => $item->id, 'warehouse_id' => $warehouseId, 'type' => $type, 'qty' => $qty,
            'unit_cost' => $qty != 0 ? round(abs($value / $qty), 4) : 0, 'value' => $value,
            'reference' => $ref, 'movement_date' => $date, 'journal_id' => $journalId, 'created_by' => Auth::id(),
            'lot_id' => $context?->lotId, 'to_lot_id' => $context?->toLotId, 'bin_id' => $context?->binId, 'to_bin_id' => $context?->toBinId,
            'stock_status' => $context?->status, 'to_stock_status' => $context?->toStatus,
            'source_type' => $context?->sourceType, 'source_id' => $context?->sourceId,
            'idempotency_key' => $context?->idempotencyKey, 'reversal_of_id' => $context?->reversalOfId,
        ]);
    }

    /** Move stock between two warehouses — quantity only, no P&L impact (value stays in inventory). */
    public function transfer(FinStockItem $item, FinWarehouse $from, FinWarehouse $to, float $qty, ?string $date = null, ?string $reference = null, ?StockContext $context = null): void
    {
        if ($qty <= 0) {
            throw new PostingException('Transfer quantity must be greater than zero.');
        }
        // A move between bins of one warehouse is a transfer too, when a context names the bins.
        if ($from->id === $to->id && ($context === null || $context->toBinId === null || $context->toBinId === $context->binId)) {
            throw new PostingException('Choose two different warehouses.');
        }
        $date = Carbon::parse($date ?? now());

        if (($refusal = $item->refusalFor('transfer')) !== null) {
            throw new PostingException($refusal);
        }

        DB::transaction(function () use ($item, $from, $to, $qty, $date, $reference, $context) {
            $this->applyBalance($item, $from->id, -$qty, $context);
            $this->applyBalance($item, $to->id, $qty, $context?->destination());

            $common = ($context?->movementMeta() ?? []) + [
                'company_id' => $item->company_id, 'entity_id' => $item->entity_id, 'item_id' => $item->id,
                'type' => 'transfer', 'unit_cost' => 0, 'value' => 0, 'movement_date' => $date,
                'created_by' => Auth::id(), 'lot_id' => $context?->lotId,
                'source_type' => $context?->sourceType, 'source_id' => $context?->sourceId,
            ];

            FinStockMovement::create($common + [
                'warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'qty' => -$qty,
                'bin_id' => $context?->binId, 'to_bin_id' => $context?->toBinId,
                'stock_status' => $context?->status, 'to_stock_status' => $context?->toStatus,
                // The key sits on the outbound leg only: a retried transfer must find one, not two.
                'idempotency_key' => $context?->idempotencyKey,
                'reference' => $reference ?: "→ {$to->name}",
            ]);
            FinStockMovement::create($common + [
                'warehouse_id' => $to->id, 'to_warehouse_id' => $from->id, 'qty' => $qty,
                'bin_id' => $context?->toBinId ?? $context?->binId, 'to_bin_id' => $context?->binId,
                'stock_status' => $context?->toStatus ?? $context?->status,
                'reference' => $reference ?: "← {$from->name}",
            ]);
        });
    }

    /**
     * Move a quantity from one status to another without moving it anywhere: quarantine, release,
     * reject, hold (MFG-008, MFG-009). No value changes -- the stock is the same stock -- and the
     * balance is untouched, because the warehouse still holds it. Only the breakdown moves, which is
     * exactly what decides whether anybody may issue it.
     */
    public function restatus(FinStockItem $item, FinWarehouse $warehouse, float $qty, StockContext $from, string $toStatus, ?string $reason = null, ?string $date = null): FinStockMovement
    {
        if ($qty <= 0) {
            throw new PostingException('Status change quantity must be greater than zero.');
        }
        if (! in_array($toStatus, FinStockPosition::STATUSES, true)) {
            throw new PostingException("Unknown stock status [{$toStatus}].");
        }
        if ($from->status === $toStatus) {
            throw new PostingException("That stock is already {$toStatus}.");
        }
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($item, $warehouse, $qty, $from, $toStatus, $reason, $date) {
            $strictFrom = new StockContext(
                binId: $from->binId, lotId: $from->lotId, status: $from->status,
                sourceType: $from->sourceType, sourceId: $from->sourceId,
                idempotencyKey: $from->idempotencyKey, toBinId: $from->toBinId, toStatus: $toStatus, strict: true,
                meta: $from->meta,
            );

            $this->applyPosition($item, $warehouse->id, -$qty, $strictFrom);
            $this->applyPosition($item, $warehouse->id, $qty, $strictFrom->destination());

            return $this->movement($item, 'status', $qty, 0, $date, $reason, null, $warehouse->id, $strictFrom);
        });
    }

    /**
     * Move a quantity from one lot to another in place -- a split or a merge (MFG-010). Same item,
     * warehouse, bin and status; nothing is valued, because the stock has not gone anywhere. The
     * movement names both lots, which is what makes the genealogy provable from the ledger.
     */
    public function relot(FinStockItem $item, FinWarehouse $warehouse, float $qty, StockContext $from, int $toLotId, ?string $reason = null, ?string $date = null): FinStockMovement
    {
        if ($qty <= 0) {
            throw new PostingException('Quantity must be greater than zero.');
        }
        if ($from->lotId === $toLotId) {
            throw new PostingException('Stock cannot be moved into the lot it is already in.');
        }
        $date = Carbon::parse($date ?? now());

        return DB::transaction(function () use ($item, $warehouse, $qty, $from, $toLotId, $reason, $date) {
            $strict = new StockContext(
                binId: $from->binId, lotId: $from->lotId, status: $from->status,
                sourceType: $from->sourceType, sourceId: $from->sourceId,
                idempotencyKey: $from->idempotencyKey, strict: true, toLotId: $toLotId,
                meta: $from->meta,
            );

            $this->applyPosition($item, $warehouse->id, -$qty, $strict);
            $this->applyPosition($item, $warehouse->id, $qty, $strict->destination());

            return $this->movement($item, 'relot', $qty, 0, $date, $reason, null, $warehouse->id, $strict);
        });
    }

    /**
     * Undo a posted movement with a new one (MFG-008 control 6). The original is never touched.
     *
     * Value is reversed by the Ledger's own reversal, so the journal is an exact mirror rather than a
     * re-costing; quantity, balance and position are put back from the same bin, lot and status; and
     * a movement can be reversed once, which the unique index on reversal_of_id guarantees.
     */
    public function reverse(FinStockMovement $original, string $reason, ?string $idempotencyKey = null): FinStockMovement
    {
        if ($original->reversal_of_id !== null) {
            throw new PostingException('A reversal cannot itself be reversed; post a fresh movement instead.');
        }
        if (FinStockMovement::withoutGlobalScopes()->where('reversal_of_id', $original->id)->exists()) {
            throw new PostingException("Movement {$original->uuid} has already been reversed.");
        }
        if (in_array($original->type, ['transfer', 'status', 'relot'], true)) {
            throw new PostingException('Reverse a transfer, a status change or a re-lot by making the opposite move.');
        }

        return DB::transaction(function () use ($original, $reason, $idempotencyKey) {
            $item = FinStockItem::withoutGlobalScopes()->lockForUpdate()->findOrFail($original->item_id);
            $qty = -1 * (float) $original->qty;

            $journalId = null;
            if ($original->journal_id) {
                $journal = \App\Modules\Finance\Accounting\Models\FinJournal::withoutGlobalScopes()->findOrFail($original->journal_id);
                $journalId = $this->ledger->reverse($journal, $reason)->id;
            }

            // FIFO: a reversed receipt takes its quantity back off the newest layers; a reversed
            // issue returns a layer at the cost it left at.
            if ($item->cost_method === 'fifo') {
                if ($qty < 0) {
                    $left = -$qty;
                    foreach (FinStockLayer::where('item_id', $item->id)->where('qty_remaining', '>', 0)->orderByDesc('received_at')->orderByDesc('id')->lockForUpdate()->get() as $layer) {
                        $take = min($left, (float) $layer->qty_remaining);
                        $layer->qty_remaining = round((float) $layer->qty_remaining - $take, 4);
                        $layer->save();
                        $left -= $take;
                        if ($left <= 0.00001) {
                            break;
                        }
                    }
                } else {
                    FinStockLayer::create(['item_id' => $item->id, 'received_at' => now(), 'qty_remaining' => $qty, 'unit_cost' => (float) $original->unit_cost]);
                }
            }

            $item->update(['qty_on_hand' => round((float) $item->qty_on_hand + $qty, 4)]);

            $context = new StockContext(
                binId: $original->bin_id, lotId: $original->lot_id,
                status: $original->stock_status ?? FinStockPosition::AVAILABLE,
                sourceType: $original->source_type, sourceId: $original->source_id,
                idempotencyKey: $idempotencyKey, reversalOfId: $original->id, strict: true,
            );

            if ($original->warehouse_id) {
                $this->applyBalance($item, (int) $original->warehouse_id, $qty, $context);
            }

            return $this->movement(
                $item, 'reversal', $qty, -1 * (float) $original->value, now(),
                "Reversal: {$reason}", $journalId, $original->warehouse_id, $context,
            );
        });
    }

    /** The id of a chart account by code, for callers that post to a named account (WIP, scrap). */
    public function accountIdFor(string $code): int
    {
        return $this->accountId($code);
    }

    /** The default warehouse for the current tenant, creating a Main Warehouse if none exists. */
    public function defaultWarehouse(): FinWarehouse
    {
        $cid = $this->currentCompany->id() ?? Auth::user()?->company_id;
        $scoped = fn ($q) => $cid ? $q->where('company_id', $cid) : $q;

        $warehouse = $scoped(FinWarehouse::query()->where('is_default', true))->first()
            ?? $scoped(FinWarehouse::query()->where('is_active', true))->orderBy('id')->first();

        return $warehouse ?: FinWarehouse::create([
            'company_id' => $cid, 'code' => 'MAIN', 'name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true,
        ]);
    }

    /**
     * Add (or subtract) a quantity from an item's balance in a warehouse -- and from the position
     * that balance breaks down into.
     *
     * Every movement of every kind passes through here, which is why the positional breakdown lives
     * here too: written by the same call, in the same transaction, the sum of an item's positions in
     * a warehouse cannot differ from its balance. A caller that names no context lands in the plain
     * available, un-binned, un-lotted position, so the callers that predate it are unaffected.
     */
    private function applyBalance(FinStockItem $item, int $warehouseId, float $delta, ?StockContext $context = null): void
    {
        if ($delta > 0) {
            $this->refuseClosedLocation($context);
        }
        $this->refuseFrozenLocation($context);
        $this->refuseOutsideAssignedWarehouse($warehouseId);
        $this->refuseBinOfAnotherWarehouse($warehouseId, $context);

        $balance = FinStockBalance::firstOrCreate(
            ['item_id' => $item->id, 'warehouse_id' => $warehouseId],
            ['company_id' => $item->company_id, 'entity_id' => $item->entity_id, 'qty' => 0],
        );
        $balance->qty = round((float) $balance->qty + $delta, 4);
        $balance->save();

        $this->applyPosition($item, $warehouseId, $delta, $context);
    }

    /**
     * A plain issue -- a dispatch, a manual stock-out -- may not take stock that is quarantined, on
     * hold, rejected, expired or reserved for somebody else (MFG-008 control 1, MFG-009: "prevent
     * rejected or quarantined goods from being dispatched"). Before this the check was the item's
     * total on hand, which counts quarantined stock as stock.
     *
     * Applies only where something in the warehouse is actually unusable or reserved, so an
     * installation that has never quarantined or reserved anything issues exactly as it always has.
     */
    private function refuseUnusableStock(FinStockItem $item, int $warehouseId, float $qty): void
    {
        $today = now()->toDateString();
        $positions = FinStockPosition::withoutGlobalScopes()
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'fin_stock_positions.lot_id')
            ->where('fin_stock_positions.item_id', $item->id)->where('fin_stock_positions.warehouse_id', $warehouseId)
            ->selectRaw("COALESCE(SUM(CASE WHEN fin_stock_positions.status = 'AVAILABLE' AND (l.expires_on IS NULL OR l.expires_on >= ?) THEN fin_stock_positions.qty ELSE 0 END), 0) AS usable", [$today])
            ->selectRaw("COALESCE(SUM(CASE WHEN fin_stock_positions.status <> 'AVAILABLE' OR l.expires_on < ? THEN fin_stock_positions.qty ELSE 0 END), 0) AS held", [$today])
            ->first();

        $reserved = (float) FinStockReservation::withoutGlobalScopes()
            ->where('item_id', $item->id)->where('warehouse_id', $warehouseId)->where('status', FinStockReservation::OPEN)
            ->selectRaw('COALESCE(SUM(qty - qty_consumed), 0) AS remaining')->value('remaining');

        $held = (float) ($positions->held ?? 0);
        if ($held <= 0.0001 && $reserved <= 0.0001) {
            return;
        }

        $usable = max(0.0, (float) ($positions->usable ?? 0) - $reserved);
        if ($qty > $usable + 0.0001) {
            throw new PostingException(sprintf(
                'Only %s of %s can be issued from that warehouse; the rest is quarantined, on hold, rejected, expired or reserved.',
                rtrim(rtrim(number_format($usable, 4, '.', ''), '0'), '.'), $item->name,
            ));
        }
    }

    /**
     * Stock never enters a bin that is blocked, restricted, structural or inactive (WMS-004).
     *
     * Checked here rather than on a screen so a receipt, a transfer, an adjustment and an API call
     * all get the same answer. Only stock arriving through a balance change is checked: a status
     * change or re-lot in place moves nothing into the bin, and a reversal must always be able to
     * put back what the original took out, or a mistake made before a bin was blocked could never
     * be corrected.
     */
    private function refuseClosedLocation(?StockContext $context): void
    {
        if ($context?->binId === null || $context->reversalOfId !== null) {
            return;
        }

        $refusal = FinStockBin::withoutGlobalScopes()->find($context->binId)?->refusalForIncoming();

        if ($refusal !== null) {
            throw new PostingException($refusal);
        }
    }

    /**
     * A bin frozen for a stock count takes no movement in or out until the count closes (WMS-071):
     * the count is only meaningful if nothing changes under the counter. A reversal is the one
     * exception, because refusing to undo a mistake leaves the mistake in the count.
     */
    /**
     * A warehouse that restricts itself to its own staff (WHMI policy `restrict_to_staff`, WMS-200)
     * takes stock movements only from people assigned to it, its manager, or holders of
     * `wms.warehouses.all`. Checked here because every movement passes through here; scheduled work
     * and system postings (no signed-in person) are not people and pass.
     */
    private function refuseOutsideAssignedWarehouse(int $warehouseId): void
    {
        $user = auth()->user();
        if ($user === null || app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }
        $w = DB::table('fin_warehouses')->where('id', $warehouseId)->first(['code', 'policies', 'manager_user_id']);
        if (! $w || ! (json_decode((string) $w->policies, true)['restrict_to_staff'] ?? false)) {
            return;
        }
        if ((int) $w->manager_user_id === (int) $user->id || $user->can('wms.warehouses.all')
            || DB::table('wms_warehouse_staff')->where('warehouse_id', $warehouseId)->where('user_id', $user->id)->where('is_active', true)->exists()) {
            return;
        }
        throw new PostingException("You are not assigned to warehouse {$w->code}, which takes stock movements only from its own staff.");
    }

    /**
     * A position names a bin of the warehouse it is in. Without this, stock could be booked to
     * warehouse A in a bin of warehouse B -- found by the warehouse benchmark (WMS-211).
     */
    private function refuseBinOfAnotherWarehouse(int $warehouseId, ?StockContext $context): void
    {
        if ($context?->binId === null) {
            return;
        }
        $binWarehouse = FinStockBin::withoutGlobalScopes()->whereKey($context->binId)->value('warehouse_id');
        if ($binWarehouse !== null && (int) $binWarehouse !== $warehouseId) {
            throw new PostingException('That location belongs to another warehouse.');
        }
    }

    private function refuseFrozenLocation(?StockContext $context): void
    {
        if ($context?->binId === null || $context->reversalOfId !== null) {
            return;
        }

        $count = FinStockBin::withoutGlobalScopes()->whereKey($context->binId)->value('frozen_by_count_id');

        if ($count !== null) {
            $number = DB::table('wms_counts')->where('id', $count)->value('number');
            throw new PostingException('Location is frozen for stock count '.($number ?? "#{$count}").'; it takes no movements until the count is closed.');
        }
    }

    private function applyPosition(FinStockItem $item, int $warehouseId, float $delta, ?StockContext $context): void
    {
        $binId = $context?->binId;
        $lotId = $context?->lotId;
        $status = $context?->status ?? FinStockPosition::AVAILABLE;

        $find = fn () => FinStockPosition::withoutGlobalScopes()
            ->where('item_id', $item->id)->where('warehouse_id', $warehouseId)->where('status', $status)
            ->when($binId === null, fn ($q) => $q->whereNull('bin_id'), fn ($q) => $q->where('bin_id', $binId))
            ->when($lotId === null, fn ($q) => $q->whereNull('lot_id'), fn ($q) => $q->where('lot_id', $lotId))
            ->lockForUpdate()
            ->first();

        $position = $find() ?? FinStockPosition::create([
            'company_id' => $item->company_id, 'entity_id' => $item->entity_id, 'item_id' => $item->id,
            'warehouse_id' => $warehouseId, 'bin_id' => $binId, 'lot_id' => $lotId,
            'status' => $status, 'qty' => 0,
        ]);

        $newQty = round((float) $position->qty + $delta, 4);

        /*
         * A strict movement may not take a position below zero: issuing lot A when only lot B is in
         * stock, or a quarantined quantity, is exactly what the controls forbid. Legacy callers --
         * invoice relief tolerates overselling by design -- pass no context and keep their old
         * behaviour.
         */
        if ($delta < 0 && $newQty < -0.0001 && ($context?->strict ?? false)) {
            throw new PostingException(sprintf(
                'Not enough %s stock of %s in that location%s: %s available, %s requested.',
                strtolower($status), $item->name, $lotId ? ' and lot' : '',
                rtrim(rtrim(number_format((float) $position->qty, 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format(-$delta, 4, '.', ''), '0'), '.'),
            ));
        }

        $position->qty = $newQty;
        $position->save();

        if ($delta > 0 && $lotId !== null) {
            $this->refuseSecondSerialUnit($lotId);
        }
    }

    /**
     * A serial number is one unit (WMS-091). Every internal move takes stock out before putting it
     * back, so after an increase the serial's positions may sum to one at most; more means a second
     * unit is being booked under a number that is already in stock. The database checks the same at
     * commit, for any path that does not come through here.
     */
    private function refuseSecondSerialUnit(int $lotId): void
    {
        $lot = DB::table('fin_stock_lots')->where('id', $lotId)->where('kind', 'SERIAL')->first(['lot_number']);
        if ($lot === null) {
            return;
        }
        $total = (float) FinStockPosition::withoutGlobalScopes()->where('lot_id', $lotId)->sum('qty');
        if ($total > 1.0001) {
            throw new PostingException("Serial number {$lot->lot_number} is already in stock; a serial number is one unit.");
        }
    }

    private function warehouseId(FinStockItem $item, ?FinWarehouse $warehouse): int
    {
        return $warehouse?->id ?? $this->defaultWarehouse()->id;
    }

    /**
     * The company's own account for a code, falling back to the shared chart.
     *
     * It used to look only at the company's own accounts once a company was in context, while
     * finance:ensure-chart counts the shared chart as covering every company -- so a tenant on the
     * shared chart was told its chart was complete and then could not post a single stock movement.
     */
    private function accountId(string $code): int
    {
        $cid = $this->currentCompany->id() ?? Auth::user()?->company_id;
        $id = FinAccount::withoutGlobalScopes()->where('code', $code)
            ->where(fn ($q) => $cid ? $q->where('company_id', $cid)->orWhereNull('company_id') : $q->whereNull('company_id'))
            ->orderByRaw('company_id IS NULL')
            ->value('id');
        if (! $id) {
            throw new PostingException("Inventory account [{$code}] is missing from the chart.");
        }

        return (int) $id;
    }
}
