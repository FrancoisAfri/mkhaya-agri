<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Models\RecallLot;
use App\Modules\Operations\Manufacturing\Services\NumberSequences;
use App\Modules\Operations\Manufacturing\Services\Recalls;
use App\Modules\Operations\Manufacturing\Services\Traceability;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recall from the warehouse's side (WMS-083), on Manufacturing's recall (MFG-010): the recall,
 * its lots, holds, releases, disposals and action log are the same records, so a recall raised in
 * either module is one recall.
 *
 * Added here: a recall can cover a whole product or a serial range, not only one lot and what it
 * went into; it shows where every affected unit is -- bin, in transit, in production -- and which
 * shipments and customers it reached; and it re-checks open orders so nothing recalled is picked.
 * Nothing is deleted or rewritten: holds and disposals are movements like any other.
 */
class WarehouseRecalls
{
    public function __construct(
        private readonly Recalls $recalls,
        private readonly Traceability $trace,
        private readonly NumberSequences $numbers,
        private readonly ItemLifecycle $lifecycle,
        private readonly Allocator $allocator,
    ) {}

    /**
     * @param array{scope:string, item_id?:?int, lot_id?:?int, serial_from?:?string, serial_to?:?string, title:string, reason:string, severity:string} $data
     */
    public function open(array $data, User $by): Recall
    {
        return $this->domain(function () use ($data, $by) {
            return DB::transaction(function () use ($data, $by) {
                $recall = match ($data['scope']) {
                    'LOT' => $this->recalls->open(FinStockLot::query()->findOrFail($data['lot_id']), $data['title'], $data['reason'], $data['severity'], $by),
                    'SERIALS' => $this->openForLots($this->serialRange($data), $data, $by),
                    'ITEM' => $this->openForItem(FinStockItem::query()->findOrFail($data['item_id']), $data, $by),
                    default => throw new RuntimeException('Recall a lot, a serial range or a whole product.'),
                };

                return $recall->fresh();
            });
        });
    }

    /** Hold everything affected, then make every open order that had it allocated find other stock. */
    public function holdAll(Recall $recall, User $by): float
    {
        return $this->domain(function () use ($recall, $by) {
            $held = $this->recalls->holdAll($recall, $by);
            foreach ($this->ordersTouching($recall) as $order) {
                $this->allocator->reallocate($order);
            }

            return $held;
        });
    }

    /** Close the recall and, for a whole-product recall, give the product back the status it had. */
    public function close(Recall $recall, string $notes, User $by): Recall
    {
        return $this->domain(function () use ($recall, $notes, $by) {
            return DB::transaction(function () use ($recall, $notes, $by) {
                $this->recalls->close($recall, $notes, $by);
                if ($recall->scope === 'ITEM' && $recall->stock_item_id) {
                    $item = FinStockItem::query()->findOrFail($recall->stock_item_id);
                    if ($item->status === FinStockItem::RECALLED) {
                        $this->lifecycle->change($item, $recall->item_status_before ?: FinStockItem::ACTIVE, "Recall {$recall->number} closed");
                    }
                }

                return $recall->fresh();
            });
        });
    }

    /**
     * Where the recall stands: every affected unit still here (by warehouse and bin, in transit
     * and in production), what has gone and to whom, and the work that remains.
     */
    public function impact(Recall $recall): array
    {
        $lotIds = $recall->lots()->pluck('lot_id')->all();
        $itemWide = $recall->scope === 'ITEM' ? (int) $recall->stock_item_id : null;
        $affects = function ($q, string $lotCol, string $itemCol) use ($lotIds, $itemWide) {
            $q->where(function ($w) use ($lotIds, $itemWide, $lotCol, $itemCol) {
                $w->whereIn($lotCol, $lotIds ?: [0]);
                if ($itemWide) {
                    $w->orWhere($itemCol, $itemWide);
                }
            });
        };

        $stock = DB::table('fin_stock_positions as p')->where('p.qty', '>', 0)
            ->tap(fn ($q) => $affects($q, 'p.lot_id', 'p.item_id'))
            ->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->orderBy('w.code')->orderBy('b.code')
            ->get(['p.id', 'i.code as item', 'i.name', 'i.uom', 'w.code as warehouse', 'b.code as bin', 'b.kind as bin_kind',
                'l.lot_number as lot', 'p.status', 'p.qty'])
            ->map(fn ($r) => (array) $r + ['in_transit' => $r->bin_kind === 'IN_TRANSIT' || $r->status === 'IN_TRANSIT',
                'held' => $r->status !== 'AVAILABLE', 'qty' => (float) $r->qty])->all();

        $production = DB::table('fin_stock_movements as m')->where('m.source_type', 'PRODUCTION_ORDER')->where('m.qty', '<', 0)
            ->tap(fn ($q) => $affects($q, 'm.lot_id', 'm.item_id'))
            ->join('mfg_production_orders as po', 'po.id', '=', 'm.source_id')
            ->whereNotIn('po.status', ['COMPLETED', 'CLOSED', 'CANCELLED'])
            ->groupBy('po.number', 'po.status')->selectRaw('po.number, po.status, SUM(ABS(m.qty)) AS qty')->get()
            ->map(fn ($r) => ['order' => $r->number, 'status' => $r->status, 'qty' => (float) $r->qty])->all();

        $shipments = DB::table('wms_package_contents as pc')
            ->tap(fn ($q) => $affects($q, 'pc.lot_id', 'pc.item_id'))
            ->join('wms_shipment_packages as sp', 'sp.package_id', '=', 'pc.package_id')
            ->join('wms_shipments as s', 's.id', '=', 'sp.shipment_id')->join('wms_orders as o', 'o.id', '=', 's.order_id')
            ->join('fin_stock_items as i', 'i.id', '=', 'pc.item_id')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'pc.lot_id')
            ->groupBy('s.number', 's.dispatched_at', 's.status', 'o.number', 'o.customer_name', 'o.ship_to', 'i.code', 'l.lot_number')
            ->selectRaw('s.number AS shipment, s.dispatched_at, s.status, o.number AS order_number, o.customer_name, o.ship_to, i.code AS item, l.lot_number AS lot, SUM(pc.qty) AS qty')
            ->orderBy('s.dispatched_at')->get()
            ->map(fn ($r) => ['shipment' => $r->shipment, 'dispatched_at' => $r->dispatched_at, 'status' => $r->status, 'order' => $r->order_number,
                'customer' => $r->customer_name, 'ship_to' => $r->ship_to, 'item' => $r->item, 'lot' => $r->lot, 'qty' => (float) $r->qty])->all();

        $notified = DB::table('mfg_recall_actions')->where('recall_id', $recall->id)->where('kind', 'CUSTOMER_NOTIFIED')->pluck('reference')
            ->map(fn ($r) => mb_strtolower(trim((string) $r)))->all();
        $customers = collect($shipments)->groupBy('customer')->map(fn ($rows, $name) => [
            'customer' => $name ?: '—', 'shipments' => $rows->pluck('shipment')->unique()->values()->all(), 'qty' => $rows->sum('qty'),
            'notified' => in_array(mb_strtolower(trim((string) $name)), $notified, true),
        ])->values()->all();

        $queue = [];
        foreach ($stock as $s) {
            if (! $s['held']) {
                $queue[] = ['kind' => 'HOLD', 'text' => "Hold {$s['qty']} {$s['uom']} of {$s['item']}".($s['lot'] ? " lot {$s['lot']}" : '')." in {$s['warehouse']}".($s['bin'] ? " {$s['bin']}" : '')];
            } elseif ($s['in_transit']) {
                $queue[] = ['kind' => 'TRANSIT', 'text' => "Intercept {$s['qty']} {$s['uom']} of {$s['item']} in transit to {$s['warehouse']}"];
            }
        }
        foreach ($customers as $c) {
            if (! $c['notified']) {
                $queue[] = ['kind' => 'NOTIFY', 'text' => "Tell {$c['customer']} (".implode(', ', $c['shipments']).')'];
            }
        }
        foreach ($production as $p) {
            $queue[] = ['kind' => 'PRODUCTION', 'text' => "Stop or segregate production order {$p['order']} ({$p['qty']} used)"];
        }

        return ['stock' => $stock, 'production' => $production, 'shipments' => $shipments, 'customers' => $customers, 'queue' => $queue,
            'totals' => ['here' => round(collect($stock)->sum('qty'), 4), 'held' => round(collect($stock)->where('held', true)->sum('qty'), 4),
                'shipped' => round(collect($shipments)->sum('qty'), 4)]];
    }

    private function openForItem(FinStockItem $item, array $data, User $by): Recall
    {
        $lots = FinStockLot::query()->where('item_id', $item->id)->get();
        $recall = $lots->isNotEmpty() ? $this->openForLots($lots, $data, $by) : $this->bare($data, $by);
        $recall->update(['scope' => 'ITEM', 'stock_item_id' => $item->id, 'item_status_before' => $item->status ?? FinStockItem::ACTIVE]);
        // Unlotted stock cannot be held lot by lot: the product itself is recalled, which stops it
        // being received, issued, picked or dispatched until the recall closes.
        if (($item->status ?? FinStockItem::ACTIVE) !== FinStockItem::RECALLED) {
            $this->lifecycle->change($item, FinStockItem::RECALLED, "Recall {$recall->number}: {$data['reason']}");
        }

        return $recall;
    }

    /** @param \Illuminate\Support\Collection<int, FinStockLot> $lots */
    private function openForLots($lots, array $data, User $by): Recall
    {
        if ($lots->isEmpty()) {
            throw new RuntimeException('No lots or serial numbers match.');
        }
        $recall = $this->recalls->open($lots->first(), $data['title'], $data['reason'], $data['severity'], $by);
        $have = $recall->lots()->pluck('lot_id')->all();
        foreach ($lots->slice(1) as $lot) {
            foreach ($this->trace->forward($lot)['nodes'] as $lotId => $node) {
                if (in_array($lotId, $have, true)) {
                    continue;
                }
                RecallLot::create(['company_id' => $recall->company_id, 'recall_id' => $recall->id, 'lot_id' => $lotId, 'item_id' => $node['item_id'],
                    'depth' => $node['depth'], 'in_stock_at_open' => $node['in_stock'], 'dispatched_at_open' => $node['dispatched']]);
                $have[] = $lotId;
            }
        }
        if (! empty($data['serial_from'])) {
            $recall->update(['scope' => 'SERIALS', 'stock_item_id' => $lots->first()->item_id, 'serial_from' => $data['serial_from'], 'serial_to' => $data['serial_to'] ?? $data['serial_from']]);
        }

        return $recall;
    }

    /** A recall of a product that has never been lot-tracked: the recall and its log, no lots. */
    private function bare(array $data, User $by): Recall
    {
        foreach (['title', 'reason'] as $f) {
            if (blank($data[$f] ?? null)) {
                throw new RuntimeException('A recall needs a title and a reason.');
            }
        }
        if (! in_array($data['severity'] ?? null, Recall::SEVERITIES, true)) {
            throw new RuntimeException('Choose the recall class.');
        }
        $companyId = (int) FinStockItem::query()->whereKey($data['item_id'])->value('company_id');

        return Recall::create([
            'company_id' => $companyId, 'number' => $this->numbers->next($companyId, 'RECALL'), 'title' => $data['title'],
            'reason' => $data['reason'], 'severity' => $data['severity'], 'status' => Recall::OPEN, 'origin_lot_id' => null,
            'scope' => 'ITEM', 'stock_item_id' => $data['item_id'],
            'opened_by' => $by->id, 'opened_at' => now(),
        ]);
    }

    /** Serial numbers are lots of one unit; a range is every one between the two, compared as text of equal length. */
    private function serialRange(array $data)
    {
        $from = trim((string) ($data['serial_from'] ?? ''));
        $to = trim((string) ($data['serial_to'] ?? '')) ?: $from;
        if ($from === '' || empty($data['item_id'])) {
            throw new RuntimeException('Give the product and the first serial number of the range.');
        }

        return FinStockLot::query()->where('item_id', $data['item_id'])
            ->whereRaw('LPAD(lot_number, 64, \'0\') BETWEEN LPAD(?, 64, \'0\') AND LPAD(?, 64, \'0\')', [$from, $to])
            ->orderBy('lot_number')->get();
    }

    /** @return \Illuminate\Support\Collection<int, FulfilmentOrder> */
    private function ordersTouching(Recall $recall)
    {
        $lotIds = $recall->lots()->pluck('lot_id')->all();

        return FulfilmentOrder::query()->whereIn('status', ['RELEASED', 'ALLOCATED', 'PARTIALLY_ALLOCATED', 'PICKING'])
            ->whereHas('lines.allocations', fn ($q) => $q->where('status', 'ACTIVE')->where(fn ($w) => $w->whereIn('lot_id', $lotIds ?: [0])
                ->when($recall->scope === 'ITEM', fn ($x) => $x->orWhereHas('line', fn ($l) => $l->where('item_id', $recall->stock_item_id)))))
            ->get();
    }

    /** Manufacturing's recall speaks DomainException; the warehouse screens speak RuntimeException. */
    private function domain(callable $work): mixed
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
