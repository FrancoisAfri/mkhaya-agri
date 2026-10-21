<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Item as MfgItem;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use App\Modules\Procurement\Models\GoodsReceivedItem;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a goods-received note's stock (WHMI decision 2): every GRN line that names a stock item
 * becomes a receipt in the ledger, against Goods Received Not Invoiced.
 *
 * Called from Procurement's own createGRN, so a receipt made on the Procurement screen and one made
 * on the warehouse's receiving screen post the same way. A line without a stock item -- a service,
 * an asset -- posts nothing, exactly as before.
 *
 * Where the stock lands is decided here, line by line: into inspection (held, opening Manufacturing's
 * incoming inspection) when the item or the warehouse demands it; into quarantine when it has too
 * little shelf life left; otherwise into the receiving dock, with a put-away task to move it on.
 */
class GrnStockPoster
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryService $inventory,
        private readonly UnitQuantity $units,
        private readonly PutawayTasks $putaway,
    ) {}

    /** @param array<int,array<string,mixed>> $extras keyed by GRN item id: lot_number, keep_rejected, excess_qty ... */
    public function post(GoodsReceivedNote $grn, array $extras = []): void
    {
        if ($grn->posted_at !== null) {
            return;
        }
        $grn->loadMissing(['items', 'purchaseOrder']);
        $lines = $grn->items->filter(fn (GoodsReceivedItem $i) => $this->stockItemId($i) !== null);
        if ($lines->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($grn, $lines, $extras) {
            $warehouse = $this->warehouseFor($grn);
            $grn->update(['warehouse_id' => $warehouse->id]);
            $dock = $this->receivingBin($warehouse);
            $needsInspection = false;
            $short = [];

            foreach ($lines as $line) {
                $extra = $extras[$line->id] ?? [];
                $item = FinStockItem::query()->findOrFail($this->stockItemId($line));
                // A module that owns the warehouse (Mkhaya Agri's farm stores) may check the receipt first
                // (and set up what it needs), and names the analysis dimensions its postings carry.
                if (app()->bound('stock.receipt_guard')) {
                    app('stock.receipt_guard')($item, $warehouse);
                }
                $poItem = $line->purchase_order_item_id ? $line->purchaseOrderItem : null;
                $uomId = $line->txn_uom_id ?? $poItem?->uom_id;
                $profile = ItemProfile::query()->where('stock_item_id', $item->id)->first();
                $mfg = MfgItem::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();

                $accepted = $this->units->normalise($item, (float) $line->accepted_qty, $uomId);
                $unitCost = $poItem && $accepted['factor'] > 0
                    ? round((float) $poItem->unit_price / $accepted['factor'], 4)
                    : (float) ($line->unit_cost ?? $item->standard_cost);

                $inspect = (bool) ($profile?->requires_inspection) || (bool) ($warehouse->policies['receiving_requires_inspection'] ?? false);
                $status = $inspect ? FinStockPosition::QUARANTINE : FinStockPosition::AVAILABLE;
                $expires = $line->expires_on ? Carbon::parse($line->expires_on) : null;
                if ($expires && $profile?->min_remaining_shelf_life_days !== null
                    && now()->diffInDays($expires, false) < (int) $profile->min_remaining_shelf_life_days) {
                    $status = FinStockPosition::QUARANTINE;
                    $short[$line->id] = (int) max(0, now()->diffInDays($expires, false));
                }
                $bin = $status === FinStockPosition::AVAILABLE ? ($line->bin_id ? FinStockBin::query()->find($line->bin_id) : $dock) : null;

                $lots = $this->lots($item, $mfg, $line, $extra, $accepted['base_qty'], $grn);
                $common = [
                    'dimensions' => app()->bound('stock.warehouse_dimensions') ? (array) app('stock.warehouse_dimensions')($warehouse) : [],
                    'contra' => $this->contraFor($grn), 'reference' => $grn->grn_number, 'status' => $status,
                    'bin_id' => $bin?->id, 'source_type' => 'GRN', 'source_id' => $grn->id,
                    'txn_uom_id' => $accepted['txn_uom_id'], 'occurred_at' => $grn->received_at ?? now(), 'reason_code' => 'RECEIPT',
                ];

                $movement = null;
                foreach ($lots as $i => [$lotId, $qty]) {
                    if ($qty <= 0) {
                        continue;
                    }
                    $movement = $this->ledger->receive($item->fresh(), $qty, $unitCost, $warehouse, $common + [
                        'lot_id' => $lotId, 'txn_qty' => count($lots) === 1 ? $accepted['txn_qty'] : $qty,
                        'idempotency_key' => "grn:{$grn->id}:item:{$line->id}:{$i}",
                    ]);

                    if ($status === FinStockPosition::AVAILABLE) {
                        $this->putaway->create($warehouse, $item, $qty, [
                            'lot_id' => $lotId, 'from_bin_id' => $bin?->id, 'source_type' => 'GRN', 'source_id' => $grn->id,
                        ]);
                    } elseif ($inspect) {
                        $needsInspection = true;
                        app(QualityControl::class)->openForReceipt([
                            'stock_item' => $item, 'warehouse' => $warehouse, 'qty' => $qty, 'lot_id' => $lotId,
                            'held_status' => $status, 'supplier_id' => $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id,
                            'purchase_order_id' => $grn->purchase_order_id, 'reference' => $grn->grn_number,
                        ], Auth::user());
                    }
                }

                // Rejected goods kept for return go in as rejected stock: physical, valued, unusable.
                $rejected = (float) $line->rejected_qty;
                if ($rejected > 0 && ($extra['keep_rejected'] ?? false)) {
                    $r = $this->units->normalise($item, $rejected, $uomId);
                    // The specific keys first: `+` keeps the left-hand value, so this order is what
                    // makes the rejected goods land as REJECTED in the returns area.
                    $this->ledger->receive($item->fresh(), $r['base_qty'], $unitCost, $warehouse, [
                        'status' => FinStockPosition::REJECTED, 'bin_id' => $this->binOfKind($warehouse, 'RETURNS')?->id,
                        'lot_id' => $lots[0][0] ?? null, 'txn_qty' => $r['txn_qty'],
                        'idempotency_key' => "grn:{$grn->id}:item:{$line->id}:rejected",
                    ] + $common);
                }

                $line->update([
                    'stock_item_id' => $item->id, 'base_qty' => $accepted['base_qty'], 'unit_cost' => $unitCost,
                    'txn_uom_id' => $accepted['txn_uom_id'], 'bin_id' => $bin?->id, 'lot_id' => $lots[0][0] ?? null,
                    'stock_status' => $status, 'movement_id' => $movement?->id,
                ]);
            }

            $grn->update([
                'posted_at' => now(),
                'inspection_status' => $needsInspection ? 'PENDING' : 'NOT_REQUIRED',
            ]);

            app(ReceiptDiscrepancies::class)->detect($grn->fresh(['items', 'purchaseOrder']), $short);

            if ($supplierId = $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id) {
                app(SupplierScorecard::class)->refreshFlag((int) $supplierId, (int) $grn->company_id);
            }
        });
    }

    /**
     * The lot(s) a line goes into. A lot-tracked item needs its batch; a serial-tracked one needs a
     * serial for every unit, each its own lot of one.
     *
     * @return list<array{0:?int,1:float}>
     */
    private function lots(FinStockItem $item, ?MfgItem $mfg, GoodsReceivedItem $line, array $extra, float $qty, GoodsReceivedNote $grn): array
    {
        $attrs = array_filter([
            'origin' => 'RECEIPT', 'expires_on' => $line->expires_on?->toDateString(), 'manufactured_on' => $line->manufactured_on?->toDateString(),
            'supplier_id' => $grn->purchaseOrder?->supplier_id ?? $grn->supplier_id, 'source_type' => 'GRN', 'source_id' => $grn->id,
            'supplier_lot_number' => $extra['supplier_lot_number'] ?? null,
        ], fn ($v) => $v !== null);

        if ($mfg?->is_serial_tracked) {
            $serials = array_values(array_unique(array_filter(array_map('trim', (array) ($line->serial_numbers ?? [])))));
            if (count($serials) !== (int) round($qty) || abs($qty - round($qty)) > 1e-6) {
                throw new RuntimeException("{$item->code} is serial-tracked: record one serial number for each of the {$qty} units (".count($serials).' given).');
            }

            return array_map(fn ($s) => [$this->ledger->lot($item, $s, ['kind' => 'SERIAL'] + $attrs)->id, 1.0], $serials);
        }

        $number = trim((string) ($extra['lot_number'] ?? ''));
        if ($number === '' && $line->lot_id) {
            // A receipt captured earlier and posted on approval already made its lot.
            return [[(int) $line->lot_id, $qty]];
        }
        if ($mfg?->is_lot_tracked && $number === '') {
            throw new RuntimeException("{$item->code} is lot-tracked: record the batch or lot number.");
        }

        return [[$number !== '' ? $this->ledger->lot($item, $number, $attrs)->id : null, $qty]];
    }

    private function stockItemId(GoodsReceivedItem $line): ?int
    {
        return $line->stock_item_id ?? ($line->purchase_order_item_id ? $line->purchaseOrderItem?->stock_item_id : null);
    }

    private function warehouseFor(GoodsReceivedNote $grn): FinWarehouse
    {
        $id = $grn->warehouse_id ?? $grn->purchaseOrder?->warehouse_id;

        return $id ? FinWarehouse::query()->findOrFail($id) : $this->inventory->defaultWarehouse();
    }

    private function receivingBin(FinWarehouse $warehouse): ?FinStockBin
    {
        return $this->binOfKind($warehouse, 'RECEIVING');
    }

    private function binOfKind(FinWarehouse $warehouse, string $kind): ?FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('kind', $kind)->where('level', 'BIN')
            ->where('is_active', true)->where('status', FinStockBin::OPEN)->orderBy('code')->first();
    }

    /** Goods from a supplier accrue against the bill to come; goods with no supplier are found stock. */
    private function contraFor(GoodsReceivedNote $grn): string
    {
        return ($grn->purchase_order_id || $grn->supplier_id)
            ? config('finance.grni_account', '2150')
            : config('finance.stock_adjustment', config('finance.cogs_account', '5000'));
    }
}
