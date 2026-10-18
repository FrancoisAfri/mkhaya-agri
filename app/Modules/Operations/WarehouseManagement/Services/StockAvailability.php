<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Support\StockStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-022: the separate quantities a warehouse needs, each defined once.
 *
 *   physical          every position, in every state, including stock in an in-transit location
 *   in transit        positions in an IN_TRANSIT location
 *   available on hand AVAILABLE positions, not in transit, not in an expired lot
 *   reserved          open reservations' remaining quantity (allocated-not-picked is a subset)
 *   available to promise = available on hand - reserved, never below zero
 *   expected receipts open purchase-order quantity not yet received
 *   backordered       demand accepted but not reserved
 *
 * Reservations are subtracted once: allocated-but-not-picked stock is reserved stock, shown as its
 * own figure for the floor, never subtracted a second time from what can be promised.
 */
class StockAvailability
{
    /** Reservation sources that represent stock allocated to an order but not yet picked. */
    public const ALLOCATION_SOURCES = ['WMS_ALLOCATION', 'SALES_ORDER', 'PRODUCTION_ORDER'];

    private const NOT_YET_OR_NO_LONGER_ORDERED = ['draft', 'pending', 'pending_approval', 'rejected', 'cancelled', 'closed'];

    /**
     * One row per item and warehouse.
     *
     * @param  list<int>|null  $itemIds
     * @return list<array<string,mixed>>
     */
    public function rows(?array $itemIds = null, ?int $warehouseId = null): array
    {
        $today = now()->toDateString();
        $availableStates = array_keys(array_filter(StockStates::RULES, fn ($r) => $r[1]));
        $in = "'".implode("','", $availableStates)."'";

        $positions = DB::table('fin_stock_positions as p')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('p.item_id', $itemIds))
            ->when($warehouseId, fn ($q) => $q->where('p.warehouse_id', $warehouseId))
            ->groupBy('p.item_id', 'p.warehouse_id')
            ->selectRaw('p.item_id, p.warehouse_id, SUM(p.qty) AS physical')
            ->selectRaw("SUM(CASE WHEN b.kind = 'IN_TRANSIT' THEN p.qty ELSE 0 END) AS in_transit")
            ->selectRaw("SUM(CASE WHEN p.status IN ({$in}) AND COALESCE(b.kind, '') <> 'IN_TRANSIT' AND (l.expires_on IS NULL OR l.expires_on >= ?) THEN p.qty ELSE 0 END) AS available_on_hand", [$today])
            ->selectRaw("SUM(CASE WHEN p.status IN ({$in}) AND l.expires_on < ? THEN p.qty ELSE 0 END) AS expired", [$today])
            ->selectRaw("SUM(CASE WHEN p.status NOT IN ({$in}) THEN p.qty ELSE 0 END) AS held")
            ->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);

        $sources = "'".implode("','", self::ALLOCATION_SOURCES)."'";
        $reservations = DB::table('fin_stock_reservations')
            ->where('status', 'OPEN')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('item_id', $itemIds))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, SUM(qty - qty_consumed) AS reserved')
            ->selectRaw("SUM(CASE WHEN source_type IN ({$sources}) THEN qty - qty_consumed ELSE 0 END) AS allocated")
            ->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);

        $expected = $this->expectedReceipts($itemIds);
        $backorders = $this->backorders($itemIds, $warehouseId);

        $keys = collect($positions->keys())->merge($reservations->keys())->unique();
        $out = [];
        foreach ($keys as $key) {
            [$itemId, $whId] = array_map('intval', explode(':', $key));
            $p = $positions[$key] ?? null;
            $r = $reservations[$key] ?? null;
            $available = (float) ($p->available_on_hand ?? 0);
            $reserved = (float) ($r->reserved ?? 0);
            $out[] = [
                'item_id' => $itemId, 'warehouse_id' => $whId,
                'physical' => (float) ($p->physical ?? 0),
                'in_transit' => (float) ($p->in_transit ?? 0),
                'held' => (float) ($p->held ?? 0),
                'expired' => (float) ($p->expired ?? 0),
                'available_on_hand' => $available,
                'reserved' => $reserved,
                'allocated_not_picked' => (float) ($r->allocated ?? 0),
                'available_to_promise' => round(max(0.0, $available - $reserved), 4),
                // Expected receipts and backorders are per item: a purchase order names a delivery
                // warehouse only once WHMI receiving links it, so they are reported once per item.
                'expected_receipts' => (float) ($expected[$itemId] ?? 0),
                'backordered' => (float) ($backorders[$key] ?? 0),
            ];
        }

        return $out;
    }

    /** @return array<string,mixed> totals for one item across warehouses */
    public function forItem(FinStockItem $item): array
    {
        $rows = $this->rows([$item->id]);
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return [
            'by_warehouse' => $rows,
            'by_state' => DB::table('fin_stock_positions')->where('item_id', $item->id)->where('qty', '!=', 0)
                ->groupBy('status')->selectRaw('status, SUM(qty) AS qty')->pluck('qty', 'status')->map(fn ($q) => (float) $q),
            'totals' => [
                'physical' => $sum('physical'), 'in_transit' => $sum('in_transit'), 'held' => $sum('held'),
                'expired' => $sum('expired'), 'available_on_hand' => $sum('available_on_hand'), 'reserved' => $sum('reserved'),
                'allocated_not_picked' => $sum('allocated_not_picked'), 'available_to_promise' => $sum('available_to_promise'),
                'expected_receipts' => (float) ($this->expectedReceipts([$item->id])[$item->id] ?? 0),
                'backordered' => $sum('backordered'),
            ],
        ];
    }

    /** @return array<int,float> item id => open PO quantity not yet received */
    private function expectedReceipts(?array $itemIds): array
    {
        if (! Schema::hasColumn('purchase_order_items', 'stock_item_id')) {
            return [];
        }

        return DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->whereNotNull('i.stock_item_id')->whereNull('o.deleted_at')
            // An order counts once approved; one still being drafted or approved is not expected yet.
            ->whereNotIn('o.status', self::NOT_YET_OR_NO_LONGER_ORDERED)
            ->where(fn ($q) => $q->whereNull('o.received_status')->orWhere('o.received_status', '!=', 'received'))
            ->when($itemIds !== null, fn ($q) => $q->whereIn('i.stock_item_id', $itemIds))
            ->groupBy('i.stock_item_id')
            ->selectRaw('i.stock_item_id, SUM(GREATEST(i.quantity - COALESCE(i.received_qty, 0), 0)) AS open_qty')
            ->pluck('open_qty', 'stock_item_id')->map(fn ($q) => (float) $q)->all();
    }

    /** @return array<string,float> "item:warehouse" => quantity on order lines not yet reserved */
    private function backorders(?array $itemIds, ?int $warehouseId): array
    {
        if (! Schema::hasTable('wms_order_lines')) {
            return [];
        }

        return DB::table('wms_order_lines as l')->join('wms_orders as o', 'o.id', '=', 'l.order_id')
            ->whereIn('o.status', ['RELEASED', 'ALLOCATED', 'PARTIALLY_ALLOCATED', 'BACKORDERED'])
            ->when($itemIds !== null, fn ($q) => $q->whereIn('l.item_id', $itemIds))
            ->when($warehouseId, fn ($q) => $q->where('o.warehouse_id', $warehouseId))
            ->groupBy('l.item_id', 'o.warehouse_id')
            ->selectRaw("l.item_id || ':' || o.warehouse_id AS k, SUM(GREATEST(l.qty - l.qty_allocated - l.qty_cancelled, 0)) AS qty")
            ->pluck('qty', 'k')->map(fn ($q) => (float) $q)->all();
    }
}
