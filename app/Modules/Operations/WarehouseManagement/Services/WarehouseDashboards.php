<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The inventory, supplier, profit-protection and executive dashboards (WMS-181..184), composed from
 * the reports the module already keeps -- valuation, scorecards, protection measures, buying advice,
 * excess stock, expiry exposure, count accuracy -- so a figure on a dashboard is the same figure as
 * on the screen it links to. The operations dashboard (WMS-180) is the module's landing page.
 *
 * Money is returned only when asked for: a caller without the accounting grant gets quantities.
 */
class WarehouseDashboards
{
    use ScopesRawQueries;

    private const COST = "CASE WHEN COALESCE(i.is_valued, true) THEN (CASE i.cost_method WHEN 'standard' THEN i.standard_cost ELSE i.avg_cost END) ELSE 0 END";

    public function inventory(array $f, bool $withValues): array
    {
        $cid = $this->tenantId();
        $base = fn () => DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->where('w.is_wms_managed', true)->where('p.qty', '>', 0)->when($cid, fn ($q, $c) => $q->where('p.company_id', $c))
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('p.warehouse_id', $v))->when($f['item'] ?? null, fn ($q, $v) => $q->where('i.code', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('p.status', $v))
            ->when(($f['warehouse_ids'] ?? null) !== null, fn ($q) => $q->whereIn('p.warehouse_id', $f['warehouse_ids']));
        $sum = 'SUM(p.qty) AS qty, SUM(p.qty * '.self::COST.') AS value';
        $money = fn ($v) => $withValues ? round((float) $v, 2) : null;

        $byStatus = $base()->groupBy('p.status')->selectRaw("p.status, {$sum}")->orderBy('p.status')->get()
            ->map(fn ($r) => ['status' => $r->status, 'qty' => round((float) $r->qty, 4), 'value' => $money($r->value)])->all();
        $byWarehouse = $base()->groupBy('w.code')->selectRaw("w.code, {$sum}, COUNT(DISTINCT p.item_id) AS items")->orderBy('w.code')->get()
            ->map(fn ($r) => ['warehouse' => $r->code, 'items' => (int) $r->items, 'qty' => round((float) $r->qty, 4), 'value' => $money($r->value)])->all();
        $top = $base()->groupBy('i.code', 'i.name', 'i.uom')->selectRaw("i.code, i.name, i.uom, {$sum}")
            ->orderByRaw($withValues ? 'SUM(p.qty * '.self::COST.') DESC' : 'SUM(p.qty) DESC')->limit(15)->get()
            ->map(fn ($r) => ['item' => $r->code, 'name' => $r->name, 'uom' => $r->uom, 'qty' => round((float) $r->qty, 4), 'value' => $money($r->value)])->all();

        // Age from the last time stock of the item came into that warehouse.
        $ageing = $base()->selectRaw("CASE
                WHEN last_in.at IS NULL OR last_in.at >= now() - interval '30 days' THEN '0-30'
                WHEN last_in.at >= now() - interval '90 days' THEN '31-90'
                WHEN last_in.at >= now() - interval '180 days' THEN '91-180'
                WHEN last_in.at >= now() - interval '365 days' THEN '181-365' ELSE '365+' END AS bucket, {$sum}")
            ->leftJoinSub(DB::table('fin_stock_movements')->whereIn('type', WarehouseEvents::RECEIVING)->where('qty', '>', 0)
                ->when($cid, fn ($q, $c) => $q->where('company_id', $c))->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, MAX(created_at) AS at'),
                'last_in', fn ($j) => $j->on('last_in.item_id', '=', 'p.item_id')->on('last_in.warehouse_id', '=', 'p.warehouse_id'))
            ->groupBy('bucket')->get()->keyBy('bucket');
        $ageing = collect(['0-30', '31-90', '91-180', '181-365', '365+'])->map(fn ($b) => ['bucket' => $b, 'qty' => round((float) ($ageing[$b]->qty ?? 0), 4),
            'value' => $money($ageing[$b]->value ?? 0)])->all();

        $expiry = collect(app(ExpiryWatch::class)->atRisk($f['warehouse_id'] ?? null, 90));
        $reservations = DB::table('fin_stock_reservations as r')->join('fin_warehouses as w', 'w.id', '=', 'r.warehouse_id')->where('w.is_wms_managed', true)
            ->where('r.status', 'OPEN')->when($cid, fn ($q, $c) => $q->where('r.company_id', $c))->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('r.warehouse_id', $v))
            ->groupBy('r.source_type')->selectRaw('r.source_type, COUNT(*) AS n, SUM(r.qty - r.qty_consumed) AS qty')->get()
            ->map(fn ($r) => ['source' => $r->source_type, 'reservations' => (int) $r->n, 'qty' => round((float) $r->qty, 4)])->all();
        $tracked = $base()->join('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')->groupBy('l.kind')->selectRaw('l.kind, COUNT(DISTINCT l.id) AS n')->pluck('n', 'kind');

        return [
            'by_status' => $byStatus, 'by_warehouse' => $byWarehouse, 'top_products' => $top, 'ageing' => $ageing,
            'expiry' => ['lots' => $expiry->count(), 'expired' => $expiry->where('days_left', '<', 0)->count(),
                'at_risk_qty' => round((float) $expiry->sum('at_risk_qty'), 4), 'exposure' => $money($expiry->sum('exposure')),
                'soonest' => $expiry->sortBy('days_left')->take(10)->map(fn ($e) => ['item' => $e['item'], 'lot' => $e['lot'], 'warehouse' => $e['warehouse'],
                    'days_left' => $e['days_left'], 'qty' => $e['qty']])->values()->all()],
            'reservations' => $reservations,
            'tracked' => ['lots' => (int) ($tracked['LOT'] ?? 0), 'serials' => (int) ($tracked['SERIAL'] ?? 0)],
            'total' => ['qty' => round(collect($byStatus)->sum('qty'), 4), 'value' => $withValues ? round(collect($byStatus)->sum('value'), 2) : null],
        ];
    }

    public function suppliers(Carbon $from, Carbon $to, ?int $supplierId, bool $withValues): array
    {
        $cid = $this->tenantId();
        $scores = collect(app(SupplierScorecard::class)->scores($from, $to, $supplierId));
        $overdue = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->when($cid, fn ($q, $c) => $q->where('o.company_id', $c))->whereNull('o.deleted_at')->whereIn('o.status', Receiving::RECEIVABLE)->whereNotNull('i.stock_item_id')
            ->whereRaw('i.quantity > COALESCE(i.received_qty, 0)')->whereDate('o.expected_delivery', '<', now()->toDateString())
            ->when($supplierId, fn ($q, $s) => $q->where('o.supplier_id', $s))
            ->groupBy('o.id', 'o.po_number', 's.company_name', 'o.expected_delivery')->orderBy('o.expected_delivery')->limit(25)
            ->get(['o.po_number', 's.company_name as supplier', 'o.expected_delivery', DB::raw('COUNT(*) AS lines')])
            ->map(fn ($r) => ['po' => $r->po_number, 'supplier' => $r->supplier, 'expected' => $r->expected_delivery,
                'days_late' => (int) Carbon::parse($r->expected_delivery)->diffInDays(now()), 'lines' => (int) $r->lines])->all();
        $recurring = DB::table('wms_receipt_discrepancies as d')->join('goods_received_notes as g', 'g.id', '=', 'd.grn_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->when($cid, fn ($q, $c) => $q->where('d.company_id', $c))->whereBetween('d.created_at', [$from, $to])
            ->when($supplierId, fn ($q, $s) => $q->where('g.supplier_id', $s))
            ->groupBy('s.company_name', 'd.kind')->havingRaw('COUNT(*) > 1')->orderByRaw('COUNT(*) DESC')->limit(20)
            ->get(['s.company_name as supplier', 'd.kind', DB::raw('COUNT(*) AS n')])->map(fn ($r) => ['supplier' => $r->supplier, 'kind' => $r->kind, 'times' => (int) $r->n])->all();
        $buy = collect(app(PurchasingAdvisor::class)->recommendations());

        return [
            'scores' => $scores->sortBy(fn ($s) => $s['on_time_rate'] ?? 2)->values()->all(),
            'overall' => ['deliveries' => (int) $scores->sum('deliveries'),
                'on_time_rate' => $scores->avg('on_time_rate') !== null ? round($scores->avg('on_time_rate'), 4) : null,
                'quantity_accuracy' => $scores->avg('quantity_accuracy') !== null ? round($scores->avg('quantity_accuracy'), 4) : null],
            'overdue' => $overdue, 'recurring_discrepancies' => $recurring,
            'replenishment' => ['items' => $buy->count(), 'value' => $withValues ? round($buy->sum(fn ($r) => $r['suggested_qty'] * $r['unit_cost']), 2) : null,
                'most_urgent' => $buy->take(10)->map(fn ($r) => ['item' => $r['code'], 'qty' => $r['suggested_qty'], 'days_of_cover' => $r['days_of_cover'], 'supplier' => $r['supplier']])->values()->all()],
        ];
    }

    public function protection(Carbon $from, Carbon $to, bool $withValues): array
    {
        $measures = app(ProtectionCases::class)->measures($from->toDateString(), $to->toDateString());
        $open = Finding::query()->whereNotNull('warehouse_id')->whereIn('status', Finding::OPEN)->get(['detected_at', 'priority', 'estimated_impact', 'category']);
        $ageing = collect(['0-7' => [0, 7], '8-30' => [8, 30], '31-90' => [31, 90], '90+' => [91, PHP_INT_MAX]])
            ->map(fn ($r, $k) => ['bucket' => $k, 'open' => $open->filter(fn ($f) => ($d = (int) $f->detected_at?->diffInDays(now())) >= $r[0] && $d <= $r[1])->count()])->values()->all();
        $register = app(LossControl::class)->register(null, $from->toDateString(), $to->toDateString());
        if (! $withValues) {
            array_walk_recursive($measures['impact'], fn (&$v) => $v = is_numeric($v) ? null : $v);
        }

        return ['impact' => $measures['impact'], 'effectiveness' => $measures['effectiveness'], 'ageing' => $ageing,
            'by_priority' => $open->countBy('priority')->all(), 'by_category' => $open->countBy('category')->all(),
            'write_offs' => $withValues ? $register['confirmed'] ?? null : null, 'recoveries' => $withValues ? $register['recoveries'] ?? null : null];
    }

    public function executive(Carbon $from, Carbon $to): array
    {
        $cost = app(CostReports::class)->report(null, $from->toDateString(), $to->toDateString());
        $excess = app(ExcessObsolete::class)->analyse()['totals'];
        $buy = collect(app(PurchasingAdvisor::class)->recommendations());
        // Exposure: demand that falls between running out and the next delivery arriving, at cost.
        $stockout = $buy->map(function ($r) {
            $in = collect($r['inputs'])->keyBy('label');
            $daily = (float) ($in['Daily demand']['value'] ?? 0);
            $lead = (float) ($in['Lead time (days)']['value'] ?? 0);
            $cover = $r['days_of_cover'];
            $gap = $cover === null ? 0 : max(0, $lead - $cover);

            return ['item' => $r['code'], 'days_short' => round($gap, 1), 'value' => round($gap * $daily * (float) $r['unit_cost'], 2)];
        })->filter(fn ($r) => $r['value'] > 0)->sortByDesc('value')->values();
        $cid = $this->tenantId();
        $orders = DB::table('wms_orders')->when($cid, fn ($q, $c) => $q->where('company_id', $c))->whereBetween('dispatched_at', [$from, $to]);
        $onTime = (clone $orders)->whereNotNull('sla_due_at')->selectRaw('COUNT(*) AS n, SUM(CASE WHEN dispatched_at <= sla_due_at THEN 1 ELSE 0 END) AS ok')->first();
        $accuracy = app(CountInsights::class)->accuracy(null, $from->toDateString(), $to->toDateString())['summary'];

        return [
            'inventory_value' => $cost['total_value'], 'carrying_cost' => $cost['carrying_cost'], 'write_offs' => $cost['write_offs'],
            'excess' => ['value' => round(collect($excess)->sum('value'), 2), 'by_class' => $excess],
            'stockout_exposure' => ['value' => round($stockout->sum('value'), 2), 'items' => $stockout->count(), 'top' => $stockout->take(10)->all()],
            'service' => ['dispatched' => (clone $orders)->count(), 'on_time_rate' => $onTime && $onTime->n ? round($onTime->ok / $onTime->n * 100, 1) : null],
            'count_accuracy' => $accuracy['accuracy'],
            // Labour and carrier charges are not recorded per order, so a cost per order would be invented.
            'fulfilment_cost' => ['carrier_quoted' => round((float) DB::table('wms_shipments')->when($cid, fn ($q, $c) => $q->where('company_id', $c))
                ->whereBetween('dispatched_at', [$from, $to])->sum('quoted_price'), 2), 'labour' => null],
        ];
    }
}
