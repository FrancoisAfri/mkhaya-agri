<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\WarehouseManagement\Models\Allocation;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fulfilment orders (WMS-060): demand from every source, with one rule in common -- the same
 * demand, by source and external reference, is accepted once. A second arrival returns the order
 * already made (so an API client retrying a timeout gets its order back, not a duplicate).
 */
class FulfilmentOrders
{
    public function __construct(private readonly StockLedger $ledger, private readonly UnitQuantity $units) {}

    /**
     * @param  list<array{item_id:int, qty:float, uom_id?:?int, lot_id?:?int, source_line_id?:?int}>  $lines
     * @return array{0:FulfilmentOrder,1:bool} the order, and whether it was created now
     */
    public function create(FinWarehouse $warehouse, string $source, string $externalRef, array $lines, array $attrs = [], ?User $user = null): array
    {
        if (! in_array($source, FulfilmentOrder::SOURCES, true)) {
            throw new RuntimeException("Unknown order source {$source}.");
        }
        $externalRef = trim($externalRef);
        if ($externalRef === '') {
            throw new RuntimeException('Every order needs its source reference, so the same demand is never accepted twice.');
        }
        if ($lines === []) {
            throw new RuntimeException('An order needs at least one line.');
        }

        $existing = FulfilmentOrder::query()->where('source', $source)->where('external_ref', $externalRef)->first();
        if ($existing) {
            return [$existing, false];
        }

        try {
            $order = DB::transaction(function () use ($warehouse, $source, $externalRef, $lines, $attrs, $user) {
                $order = FulfilmentOrder::create([
                    'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id),
                    'warehouse_id' => $warehouse->id, 'source' => $source, 'external_ref' => $externalRef, 'status' => 'NEW',
                    'created_by' => $user?->id,
                ] + array_intersect_key($attrs, array_flip(['source_id', 'customer_party_id', 'customer_name', 'ship_to', 'priority',
                    'required_by', 'allow_partial', 'allow_substitution', 'carrier', 'notes'])));

                foreach (array_values($lines) as $i => $l) {
                    $item = FinStockItem::query()->findOrFail($l['item_id']);
                    $n = $this->units->normalise($item, (float) $l['qty'], $l['uom_id'] ?? null);
                    if ($n['base_qty'] <= 0) {
                        throw new RuntimeException('Every line needs a quantity above zero.');
                    }
                    $order->lines()->create([
                        'company_id' => $warehouse->company_id, 'line_no' => $i + 1, 'item_id' => $item->id, 'qty' => $n['base_qty'],
                        'txn_uom_id' => $n['txn_uom_id'], 'txn_qty' => $n['txn_qty'], 'lot_id' => $l['lot_id'] ?? null,
                        'source_line_id' => $l['source_line_id'] ?? null,
                    ]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException) {
            // Two arrivals of the same demand raced past the check above; the database kept one.
            return [FulfilmentOrder::query()->where('source', $source)->where('external_ref', $externalRef)->firstOrFail(), false];
        }

        return [$order->fresh('lines'), true];
    }

    /** A Finance sales order's undelivered stock lines, as a fulfilment order. */
    public function fromSalesOrder(FinSalesOrder $so, FinWarehouse $warehouse, ?User $user = null, array $attrs = []): FulfilmentOrder
    {
        if (in_array($so->status, ['invoiced', 'cancelled', 'delivered'], true)) {
            throw new RuntimeException("Sales order {$so->number} is {$so->status}; there is nothing left to fulfil.");
        }
        $inventory = app(InventoryService::class);
        $lines = [];
        foreach ($so->lines as $l) {
            $remaining = (float) $l->quantity - (float) $l->qty_delivered;
            $stock = $l->item_id ? $inventory->stockItemForSaleItem((int) $l->item_id, $so->company_id) : null;
            if ($stock && $remaining > 0) {
                $lines[] = ['item_id' => $stock->id, 'qty' => $remaining, 'source_line_id' => $l->id];
            }
        }
        if ($lines === []) {
            throw new RuntimeException("Sales order {$so->number} has no stocked lines left to deliver.");
        }
        $party = $so->party_id ? DB::table('fin_parties')->where('id', $so->party_id)->first(['id', 'name', 'address', 'city']) : null;

        return $this->create($warehouse, 'SALES_ORDER', $so->number, $lines, $attrs + [
            'source_id' => $so->id, 'customer_party_id' => $party?->id, 'customer_name' => $party?->name,
            'ship_to' => $party ? array_filter(['name' => $party->name, 'address' => $party->address, 'city' => $party->city]) : null,
        ], $user)[0];
    }

    /** A production order's outstanding components, as a material request to pick (WMS-060). */
    public function fromProductionOrder(ProductionOrder $po, FinWarehouse $warehouse, ?User $user = null): FulfilmentOrder
    {
        $lines = DB::table('mfg_production_order_components')->where('order_id', $po->id)->whereNotNull('stock_item_id')
            ->get()->map(fn ($c) => ['item_id' => $c->stock_item_id, 'qty' => round((float) $c->required_qty - (float) $c->issued_qty + (float) $c->returned_qty, 4)])
            ->filter(fn ($l) => $l['qty'] > 0)->values()->all();
        if ($lines === []) {
            throw new RuntimeException("Production order {$po->number} needs nothing more from the warehouse.");
        }

        return $this->create($warehouse, 'MANUFACTURING', $po->number, $lines, [
            'source_id' => $po->id, 'customer_name' => "Production {$po->number}", 'priority' => 70,
            'required_by' => $po->planned_start ?? null, 'allow_partial' => true,
        ], $user)[0];
    }

    /** Ready for the floor: the service-level clock starts here (WMS-066). */
    public function release(FulfilmentOrder $order): FulfilmentOrder
    {
        if ($order->status !== 'NEW') {
            throw new RuntimeException("{$order->number} has already been released.");
        }
        $warehouse = $order->warehouse;
        $hours = (float) ($warehouse->policies['sla_dispatch_hours'] ?? 24);
        $due = now()->addMinutes((int) round($hours * 60));
        if ($order->required_by && $order->required_by->lt($due)) {
            $due = $order->required_by;
        }
        $order->update(['status' => 'RELEASED', 'released_at' => now(), 'sla_due_at' => $due]);

        return $order;
    }

    /**
     * Cancel what has not shipped (WMS-061 "safe reservation release"): every reservation is let
     * go, open picks are cancelled, and stock already picked to the packing area gets a put-away
     * task back to storage.
     */
    public function cancel(FulfilmentOrder $order, string $reason, ?User $user = null): FulfilmentOrder
    {
        if (in_array($order->status, ['DISPATCHED', 'DELIVERED', 'CANCELLED'], true)) {
            throw new RuntimeException("{$order->number} is ".strtolower($order->status).' and cannot be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the order is cancelled.');
        }

        return DB::transaction(function () use ($order, $reason) {
            foreach ($order->lines as $line) {
                foreach ($line->allocations()->whereIn('status', ['ACTIVE', 'PICKED'])->get() as $a) {
                    $this->ledger->releaseFor('WMS_ALLOCATION', $a->id, "Order {$order->number} cancelled: {$reason}");
                    $back = round((float) $a->qty_picked - (float) $a->qty_dispatched, 4);
                    if ($back > 0) {
                        app(PutawayTasks::class)->create($order->warehouse, $line->item, $back, [
                            'lot_id' => $a->lot_id, 'from_bin_id' => $this->packingBin($order->warehouse)->id,
                            'source_type' => 'WMS_ORDER_CANCELLED', 'source_id' => $order->id, 'priority' => 70,
                        ]);
                    }
                    $a->update(['status' => 'RELEASED', 'release_reason' => $reason]);
                }
                $line->update(['qty_cancelled' => round((float) $line->qty - (float) $line->qty_dispatched, 4)]);
            }
            PickTask::query()->where('order_id', $order->id)->where('status', 'OPEN')->update(['status' => 'CANCELLED']);
            $order->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);

            return $order;
        });
    }

    /** Where picked stock waits to be packed: the warehouse's first dispatch bay, made if there is none. */
    public function packingBin(FinWarehouse $warehouse): FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('kind', 'DISPATCH')->where('level', 'BIN')
            ->where('is_active', true)->orderBy('id')->first()
            ?? FinStockBin::create([
                'company_id' => $warehouse->company_id, 'entity_id' => $warehouse->entity_id, 'warehouse_id' => $warehouse->id,
                'code' => 'PACK', 'name' => 'Packing and dispatch', 'kind' => 'DISPATCH', 'level' => 'BIN',
                'barcode' => $warehouse->code.'.PACK', 'is_active' => true, 'status' => FinStockBin::OPEN,
            ]);
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-order-number:'.$companyId)]);
        $prefix = 'FO-'.now()->format('Y').'-';
        $last = FulfilmentOrder::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }
}
