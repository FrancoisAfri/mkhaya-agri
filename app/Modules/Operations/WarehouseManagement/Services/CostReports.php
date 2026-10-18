<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Facades\DB;

/**
 * What the stock costs (WMS-103): its value by warehouse, the cost of carrying it where the
 * warehouse has set a carrying rate, the write-offs, the landed costs by component, the price
 * variance on what was bought, and the value tied up in stock that does not move or is above its
 * maximum. Every figure says what it is computed from; nothing is shown where there is no data.
 */
class CostReports
{
    use ScopesRawQueries;

    public function __construct(private readonly LossControl $losses) {}

    public function report(?int $warehouseId, string $from, string $to, int $slowDays = 90): array
    {
        $cid = $this->tenantId();
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $cost = "CASE WHEN COALESCE(i.is_valued, true) THEN (CASE i.cost_method WHEN 'standard' THEN i.standard_cost ELSE i.avg_cost END) ELSE 0 END";

        $byWarehouse = DB::table('fin_stock_balances as b')->join('fin_stock_items as i', 'i.id', '=', 'b.item_id')->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->when($cid, fn ($q, $c) => $q->where('b.company_id', $c))->when($warehouseId, fn ($q, $v) => $q->where('b.warehouse_id', $v))
            ->where('b.qty', '>', 0)->groupBy('w.id', 'w.code', 'w.name', 'w.policies')
            ->selectRaw("w.id, w.code, w.name, w.policies, SUM(b.qty * {$cost}) AS value, SUM(CASE WHEN COALESCE(i.is_valued, true) THEN 0 ELSE b.qty END) AS client_qty, COUNT(DISTINCT b.item_id) AS items")
            ->get()->map(function ($r) use ($from, $to) {
                $policies = json_decode((string) $r->policies, true) ?: [];
                $rate = isset($policies['carrying_cost_pct']) ? (float) $policies['carrying_cost_pct'] : null;
                $days = max(1, now()->parse($from)->diffInDays(now()->parse($to)) + 1);

                return ['warehouse' => $r->code, 'name' => $r->name, 'items' => (int) $r->items, 'value' => round((float) $r->value, 2),
                    'client_qty' => round((float) $r->client_qty, 4), 'carrying_rate' => $rate,
                    // Carrying cost for the period at the warehouse's annual rate, on today's value: an estimate, labelled as one.
                    'carrying_cost' => $rate !== null ? round((float) $r->value * $rate / 100 * $days / 365, 2) : null];
            })->values()->all();

        $landed = DB::table('wms_landed_costs')->where('status', 'POSTED')->whereBetween('posted_at', $range)
            ->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get(['components', 'to_inventory', 'to_cogs']);
        $components = [];
        foreach ($landed as $lc) {
            foreach (json_decode((string) $lc->components, true) ?: [] as $c) {
                $components[$c['kind']] = round(($components[$c['kind']] ?? 0) + (float) $c['amount'], 2);
            }
        }

        // Price variance: what receipts cost against the item's standard (or, for averaged items, its average before).
        $ppv = DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')
            ->where('m.type', 'receipt')->where('m.source_type', 'GRN')->where('m.qty', '>', 0)->whereNull('m.reversal_of_id')
            ->whereBetween('m.created_at', $range)->when($cid, fn ($q, $c) => $q->where('m.company_id', $c))
            ->when($warehouseId, fn ($q, $v) => $q->where('m.warehouse_id', $v))->where('i.standard_cost', '>', 0)
            ->groupBy('i.code', 'i.name')->selectRaw('i.code, i.name, SUM(m.qty) AS qty, SUM(m.value) AS paid, SUM(m.qty * i.standard_cost) AS standard')
            ->get()->map(fn ($r) => ['item' => $r->code, 'name' => $r->name, 'qty' => (float) $r->qty, 'paid' => round((float) $r->paid, 2),
                'standard' => round((float) $r->standard, 2), 'variance' => round((float) $r->paid - (float) $r->standard, 2)])
            ->filter(fn ($r) => abs($r['variance']) >= 0.01)->sortByDesc(fn ($r) => abs($r['variance']))->values()->all();

        // Slow-moving: valued stock nothing has issued, sold or dispatched for the window.
        $lastOut = DB::table('fin_stock_movements')->where('qty', '<', 0)->whereNotIn('type', ['transfer', 'status', 'relot'])
            ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, MAX(movement_date) AS last_out');
        $slow = DB::table('fin_stock_balances as b')->join('fin_stock_items as i', 'i.id', '=', 'b.item_id')->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoinSub($lastOut, 'o', fn ($j) => $j->on('o.item_id', '=', 'b.item_id')->on('o.warehouse_id', '=', 'b.warehouse_id'))
            ->when($cid, fn ($q, $c) => $q->where('b.company_id', $c))->when($warehouseId, fn ($q, $v) => $q->where('b.warehouse_id', $v))
            ->where('b.qty', '>', 0)->where(fn ($q) => $q->whereNull('o.last_out')->orWhere('o.last_out', '<', now()->subDays($slowDays)->toDateString()))
            ->orderByRaw("b.qty * {$cost} DESC")->limit(50)
            ->get(['i.code', 'i.name', 'w.code as warehouse', 'b.qty', 'o.last_out', DB::raw("b.qty * {$cost} AS value")])
            ->map(fn ($r) => ['item' => $r->code, 'name' => $r->name, 'warehouse' => $r->warehouse, 'qty' => (float) $r->qty,
                'last_out' => $r->last_out, 'value' => round((float) $r->value, 2)])->all();

        // Excess: above the maximum the product profile sets.
        $excess = DB::table('fin_stock_items as i')->join('wms_item_profiles as p', 'p.stock_item_id', '=', 'i.id')
            ->when($cid, fn ($q, $c) => $q->where('i.company_id', $c))->whereNotNull('p.max_stock')->where('p.max_stock', '>', 0)
            ->whereColumn('i.qty_on_hand', '>', 'p.max_stock')
            ->get(['i.code', 'i.name', 'i.qty_on_hand', 'p.max_stock', DB::raw("(i.qty_on_hand - p.max_stock) * {$cost} AS value")])
            ->map(fn ($r) => ['item' => $r->code, 'name' => $r->name, 'on_hand' => (float) $r->qty_on_hand, 'max' => (float) $r->max_stock,
                'excess' => round((float) $r->qty_on_hand - (float) $r->max_stock, 4), 'value' => round((float) $r->value, 2)])->all();

        $losses = $this->losses->register($warehouseId, $from, $to);

        return [
            'warehouses' => $byWarehouse,
            'total_value' => round(array_sum(array_column($byWarehouse, 'value')), 2),
            'carrying_cost' => collect($byWarehouse)->every(fn ($w) => $w['carrying_cost'] === null) ? null : round(collect($byWarehouse)->sum('carrying_cost'), 2),
            'write_offs' => ['confirmed' => $losses['confirmed']['total'], 'suspected' => $losses['suspected']['total'], 'recovered' => $losses['recoveries']['recovered']],
            'landed' => ['components' => $components, 'to_inventory' => round($landed->sum('to_inventory'), 2), 'to_cogs' => round($landed->sum('to_cogs'), 2)],
            'price_variance' => ['total' => round(array_sum(array_column($ppv, 'variance')), 2), 'items' => array_slice($ppv, 0, 25)],
            'slow_moving' => ['days' => $slowDays, 'value' => round(array_sum(array_column($slow, 'value')), 2), 'items' => $slow],
            'excess' => ['value' => round(array_sum(array_column($excess, 'value')), 2), 'items' => $excess],
        ];
    }
}
