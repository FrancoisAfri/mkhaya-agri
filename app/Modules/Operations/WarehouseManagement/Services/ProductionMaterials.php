<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Facades\DB;

/**
 * The warehouse's view of production (WMS-160 to WMS-163), read from Manufacturing's own production
 * orders and components -- never a copy of them (WMS-165).
 *
 * For each order still to make or being made: what its bill of materials needs, what is reserved
 * for it, what is free in the component warehouse, what is short before it can be released, what
 * has been issued and returned, and -- for work done -- whether more material went in than the good
 * output should have used. Finished goods awaiting put-away are listed with them.
 */
class ProductionMaterials
{
    use ScopesRawQueries;

    public function board(): array
    {
        $cid = $this->tenantId();
        $threshold = $cid ? (float) app(LossRules::class)->for($cid, 'EXCESS_MATERIAL')->threshold : 5.0;
        $orders = PO::query()->whereIn('status', [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::COMPLETED])
            ->with('item.stockItem:id,code,name')->orderBy('planned_start')->limit(100)->get();
        $components = DB::table('mfg_production_order_components as c')->join('fin_stock_items as i', 'i.id', '=', 'c.stock_item_id')
            ->whereIn('c.order_id', $orders->pluck('id'))->get(['c.order_id', 'c.stock_item_id', 'c.required_qty', 'c.issued_qty', 'c.returned_qty', 'i.code', 'i.uom'])->groupBy('order_id');
        $reserved = DB::table('fin_stock_reservations')->where('source_type', PO::SOURCE_TYPE)->where('status', 'OPEN')->whereIn('source_id', $orders->pluck('id'))
            ->groupBy('source_id', 'item_id')->selectRaw('source_id, item_id, SUM(qty - qty_consumed) AS q')->get()->keyBy(fn ($r) => $r->source_id.':'.$r->item_id);
        $staged = DB::table('fin_stock_positions as p')->join('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')->where('b.kind', 'PRODUCTION')->where('p.qty', '>', 0)
            ->when($cid, fn ($q, $c) => $q->where('p.company_id', $c))->groupBy('p.item_id', 'p.warehouse_id')->selectRaw('p.item_id, p.warehouse_id, SUM(p.qty) AS q')
            ->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);
        $atp = collect(app(StockAvailability::class)->rows($components->flatten(1)->pluck('stock_item_id')->unique()->values()->all()))->keyBy(fn ($r) => $r['item_id'].':'.$r['warehouse_id']);

        $rows = $orders->map(function (PO $o) use ($components, $reserved, $staged, $atp, $threshold) {
            $wh = (int) ($o->component_warehouse_id ?: $o->warehouse_id);
            $share = (float) $o->planned_qty > 0 ? ((float) $o->qty_completed + (float) $o->qty_scrapped) / (float) $o->planned_qty : 0.0;
            $lines = ($components[$o->id] ?? collect())->map(function ($c) use ($o, $reserved, $staged, $atp, $wh, $share, $threshold) {
                $need = round((float) $c->required_qty - (float) $c->issued_qty + (float) $c->returned_qty, 4);
                $res = (float) ($reserved[$o->id.':'.$c->stock_item_id]->q ?? 0);
                $free = (float) ($atp[$c->stock_item_id.':'.$wh]['available_to_promise'] ?? 0);
                $used = round((float) $c->issued_qty - (float) $c->returned_qty, 4);
                $expected = round((float) $c->required_qty * $share, 4);
                $variance = $expected > 0 ? round(($used - $expected) / $expected * 100, 1) : null;

                return ['item' => $c->code, 'uom' => $c->uom, 'required' => (float) $c->required_qty, 'issued' => (float) $c->issued_qty, 'returned' => (float) $c->returned_qty,
                    'still_needed' => max(0.0, $need), 'reserved' => $res, 'free' => $free,
                    // Short: what is still needed and neither reserved for this order nor free to reserve.
                    'short' => round(max(0.0, $need - $res - $free), 4), 'staged' => (float) ($staged[$c->stock_item_id.':'.$wh]->q ?? 0),
                    'expected_for_output' => $expected, 'used' => $used, 'variance_pct' => $variance,
                    'flag' => $variance !== null && $share > 0 && abs($variance) > $threshold];
            })->values();

            return ['id' => $o->uuid, 'number' => $o->number, 'status' => $o->status, 'item' => $o->item?->stockItem?->code, 'item_name' => $o->item?->stockItem?->name,
                'planned_qty' => (float) $o->planned_qty, 'completed' => (float) $o->qty_completed, 'scrapped' => (float) $o->qty_scrapped,
                'planned_start' => $o->planned_start?->toDateString(), 'lines' => $lines, 'short_lines' => $lines->where('short', '>', 0)->count(),
                'flags' => $lines->where('flag', true)->count(), 'can_release' => $lines->where('short', '>', 0)->isEmpty(),
                'warehouse_order' => DB::table('wms_orders')->where('source', 'MANUFACTURING')->where('source_id', $o->id)->whereNotIn('status', ['CANCELLED'])->value('number')];
        })->values()->all();

        $awaiting = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')->where('w.is_wms_managed', true)->whereNull('p.bin_id')->where('p.qty', '>', 0)->where('p.status', 'AVAILABLE')
            ->when($cid, fn ($q, $c) => $q->where('p.company_id', $c))
            ->whereExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.item_id', 'p.item_id')->where('m.type', 'mfg_receipt'))
            ->whereNotExists(fn ($q) => $q->from('wms_putaway_tasks as t')->whereColumn('t.item_id', 'p.item_id')->whereColumn('t.warehouse_id', 'p.warehouse_id')->whereIn('t.status', ['OPEN', 'IN_PROGRESS']))
            ->get(['p.id as position', 'i.code', 'i.name', 'w.code as warehouse', 'l.lot_number as lot', 'p.qty'])->map(fn ($r) => (array) $r)->all();

        return ['orders' => $rows, 'finished_awaiting_putaway' => $awaiting, 'variance_threshold' => $threshold];
    }
}
