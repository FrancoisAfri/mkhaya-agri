<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Batches and serial numbers (WMS-090, WMS-091), read from the lots and the ledger: what a batch
 * is (origin, supplier, production, dates), where every unit of it is and in what state, and
 * everything that ever happened to it -- received, moved, inspected, issued, returned, shipped.
 */
class Batches
{
    use ScopesRawQueries;

    public function __construct(private readonly ClientVisibility $clients) {}

    /** @param array{q?:?string, kind?:?string, item_id?:?int, expiring_within?:?int, in_stock?:bool} $f */
    public function register(array $f, int $perPage = 50): LengthAwarePaginator
    {
        $q = DB::table('fin_stock_lots as l')->join('fin_stock_items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('fin_parties as s', 's.id', '=', 'l.supplier_id')
            ->leftJoinSub(DB::table('fin_stock_positions')->groupBy('lot_id')
                ->selectRaw("lot_id, SUM(qty) AS on_hand, SUM(CASE WHEN status = 'AVAILABLE' THEN qty ELSE 0 END) AS available, COUNT(DISTINCT warehouse_id) AS warehouses"), 'p', 'p.lot_id', '=', 'l.id')
            ->leftJoinSub(DB::table('fin_stock_movements')->where('qty', '>', 0)->whereNull('reversal_of_id')->groupBy('lot_id')
                ->selectRaw('lot_id, MIN(movement_date) AS received_on'), 'r', 'r.lot_id', '=', 'l.id')
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x->where('l.lot_number', 'ilike', "%{$v}%")
                ->orWhere('l.supplier_lot_number', 'ilike', "%{$v}%")->orWhere('i.code', 'ilike', "%{$v}%")))
            ->when($f['kind'] ?? null, fn ($w, $v) => $w->where('l.kind', $v))
            ->when($f['item_id'] ?? null, fn ($w, $v) => $w->where('l.item_id', $v))
            ->when($f['expiring_within'] ?? null, fn ($w, $v) => $w->whereNotNull('l.expires_on')->where('l.expires_on', '<=', now()->addDays((int) $v)->toDateString()))
            ->when($f['in_stock'] ?? false, fn ($w) => $w->where('p.on_hand', '>', 0))
            ->when($this->tenantId(), fn ($w, $c) => $w->where('l.company_id', $c));
        $this->clients->scope($q, 'l.item_id');

        return $q->orderByRaw('l.expires_on ASC NULLS LAST')->orderByDesc('l.id')
            ->select('l.uuid', 'l.lot_number', 'l.kind', 'l.expires_on', 'l.manufactured_on', 'l.origin', 'l.supplier_lot_number',
                'i.code as item', 'i.name as item_name', 'i.uom', 'i.uuid as item_id', 's.name as supplier', 'p.on_hand', 'p.available', 'p.warehouses', 'r.received_on')
            ->paginate($perPage)->withQueryString()
            ->through(fn ($r) => [
                'id' => $r->uuid, 'lot' => $r->lot_number, 'kind' => $r->kind, 'item' => $r->item, 'item_name' => $r->item_name, 'item_id' => $r->item_id,
                'uom' => $r->uom, 'supplier' => $r->supplier, 'supplier_lot' => $r->supplier_lot_number, 'origin' => $r->origin,
                'manufactured_on' => $r->manufactured_on, 'expires_on' => $r->expires_on, 'received_on' => $r->received_on,
                'days_left' => $r->expires_on ? (int) now()->startOfDay()->diffInDays($r->expires_on, false) : null,
                'on_hand' => (float) $r->on_hand, 'available' => (float) $r->available, 'warehouses' => (int) $r->warehouses,
            ]);
    }

    /** Everything about one batch or serial number. */
    public function detail(FinStockLot $lot): array
    {
        $positions = DB::table('fin_stock_positions as p')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->where('p.lot_id', $lot->id)->where('p.qty', '!=', 0)
            ->orderBy('w.code')->orderBy('b.code')->get(['w.code as warehouse', 'b.code as bin', 'b.kind as bin_kind', 'p.status', 'p.qty'])
            ->map(fn ($r) => ['warehouse' => $r->warehouse, 'bin' => $r->bin, 'bin_kind' => $r->bin_kind, 'status' => $r->status, 'qty' => (float) $r->qty])->all();

        $history = DB::table('fin_stock_movements as m')->leftJoin('fin_warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'm.bin_id')->leftJoin('fin_stock_bins as tb', 'tb.id', '=', 'm.to_bin_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->where(fn ($q) => $q->where('m.lot_id', $lot->id)->orWhere('m.to_lot_id', $lot->id))->orderBy('m.id')
            ->get(['m.uuid', 'm.type', 'm.qty', 'm.movement_date', 'm.created_at', 'm.reference', 'm.reason_code', 'm.stock_status', 'm.to_stock_status',
                'm.source_type', 'w.code as warehouse', 'b.code as bin', 'tb.code as to_bin', 'u.email as by'])
            ->map(fn ($m) => ['id' => $m->uuid, 'type' => $m->type, 'qty' => (float) $m->qty, 'date' => $m->movement_date, 'at' => $m->created_at,
                'reference' => $m->reference, 'reason_code' => $m->reason_code, 'from_status' => $m->stock_status, 'to_status' => $m->to_stock_status,
                'source' => $m->source_type, 'warehouse' => $m->warehouse, 'bin' => $m->bin, 'to_bin' => $m->to_bin, 'by' => $m->by])->all();

        $inspections = DB::table('mfg_inspections')->where('lot_id', $lot->id)->orderBy('id')
            ->get(['number', 'stage', 'result', 'disposition', 'created_at'])->map(fn ($i) => (array) $i)->all();
        $recalls = DB::table('mfg_recall_lots as rl')->join('mfg_recalls as r', 'r.id', '=', 'rl.recall_id')->where('rl.lot_id', $lot->id)
            ->get(['r.uuid', 'r.number', 'r.title', 'r.status'])->map(fn ($r) => (array) $r)->all();
        $shipments = DB::table('wms_package_contents as pc')->join('wms_shipment_packages as sp', 'sp.package_id', '=', 'pc.package_id')
            ->join('wms_shipments as s', 's.id', '=', 'sp.shipment_id')->join('wms_orders as o', 'o.id', '=', 's.order_id')
            ->where('pc.lot_id', $lot->id)->groupBy('s.number', 's.dispatched_at', 'o.number', 'o.customer_name')
            ->selectRaw('s.number AS shipment, s.dispatched_at, o.number AS order_number, o.customer_name, SUM(pc.qty) AS qty')->get()
            ->map(fn ($r) => ['shipment' => $r->shipment, 'dispatched_at' => $r->dispatched_at, 'order' => $r->order_number, 'customer' => $r->customer_name, 'qty' => (float) $r->qty])->all();
        // Genealogy (WMS-164), from Manufacturing's traceability: what this lot was made from, and what it went into.
        $trace = app(\App\Modules\Operations\Manufacturing\Services\Traceability::class);
        $chain = fn (array $walk) => collect($walk['nodes'] ?? [])->except([$lot->id])->map(fn ($n) => ['lot' => $n['uuid'], 'number' => $n['lot'],
            'item' => $n['item_code'], 'depth' => $n['depth'], 'in_stock' => (float) $n['in_stock']])->sortBy('depth')->values()->all();
        $genealogy = ['made_from' => $chain($trace->backward($lot)), 'went_into' => $chain($trace->forward($lot))];
        $item = DB::table('fin_stock_items')->where('id', $lot->item_id)->first(['uuid', 'code', 'name', 'uom']);
        $supplier = $lot->supplier_id ? DB::table('fin_parties')->where('id', $lot->supplier_id)->value('name') : null;
        $onHand = round(array_sum(array_column($positions, 'qty')), 4);

        return [
            'lot' => ['id' => $lot->uuid, 'number' => $lot->lot_number, 'kind' => $lot->kind, 'item' => $item?->code, 'item_name' => $item?->name,
                'item_id' => $item?->uuid, 'uom' => $item?->uom, 'origin' => $lot->origin, 'supplier' => $supplier, 'supplier_lot' => $lot->supplier_lot_number,
                'manufactured_on' => $lot->manufactured_on?->toDateString(), 'expires_on' => $lot->expires_on?->toDateString(),
                'expired' => $lot->isExpiredOn(), 'source' => $lot->source_type ? "{$lot->source_type} #{$lot->source_id}" : null, 'notes' => $lot->notes,
                'received_on' => collect($history)->firstWhere(fn ($h) => $h['qty'] > 0)['date'] ?? null, 'on_hand' => $onHand,
                // A serial is "where" it is; a batch is spread across places.
                'serial_state' => $lot->kind === 'SERIAL' ? ($onHand > 0 ? 'IN_STOCK' : (count($shipments) ? 'SHIPPED' : 'OUT')) : null],
            'positions' => $positions, 'history' => $history, 'inspections' => $inspections, 'recalls' => $recalls, 'shipments' => $shipments,
            'genealogy' => $genealogy,
        ];
    }
}
