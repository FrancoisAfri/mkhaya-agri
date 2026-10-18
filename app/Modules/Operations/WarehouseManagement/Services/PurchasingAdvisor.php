<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Services\SupplierInsight;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What to buy, how much, and from whom (WMS-121, WMS-123) -- advice, never an order.
 *
 * Each recommendation shows every input it used and where that input came from: demand from the
 * latest forecast (or the recent average when there is none), lead time and order rules from the
 * supplier's terms (or the product's), safety stock as set, or computed from the forecast's spread
 * at the warehouse's service level. Raising it goes through Procurement's purchase request, so
 * approvals, budgets and supplier controls apply exactly as they would to anybody's request (WMS-124).
 */
class PurchasingAdvisor
{
    use ScopesRawQueries;

    /** Service level => z, for the computed safety stock. */
    private const Z = [90 => 1.28, 95 => 1.65, 97 => 1.88, 98 => 2.05, 99 => 2.33];

    public function __construct(
        private readonly StockAvailability $availability,
        private readonly DemandForecaster $forecaster,
        private readonly UnitQuantity $units,
    ) {}

    /** @return list<array<string,mixed>> */
    public function recommendations(int $serviceLevel = 95): array
    {
        $cid = $this->tenantId();
        $profiles = ItemProfile::query()->get()->keyBy('stock_item_id');
        $params = DB::table('mfg_planning_params')->when($cid, fn ($q, $c) => $q->where('company_id', $c))->get()->keyBy('stock_item_id');
        $forecastItems = DB::table('wms_forecasts')->when($cid, fn ($q, $c) => $q->where('company_id', $c))->distinct()->pluck('item_id');
        $items = FinStockItem::query()->where('status', FinStockItem::ACTIVE)->where(fn ($q) => $q->whereNull('is_valued')->orWhere('is_valued', true))
            ->where(fn ($q) => $q->where('reorder_level', '>', 0)->orWhereIn('id', $profiles->filter(fn ($p) => $p->safety_stock > 0 || $p->max_stock > 0 || $p->reorder_qty > 0)->keys())
                ->orWhereIn('id', $params->keys())->orWhereIn('id', $forecastItems))->get();
        if ($items->isEmpty()) {
            return [];
        }
        $rows = collect($this->availability->rows($items->pluck('id')->all()))->groupBy('item_id');
        $history = $this->historyDaily($items->pluck('id')->all());
        $terms = DB::table('wms_supplier_terms as t')->join('suppliers as s', 's.id', '=', 't.supplier_id')->whereIn('t.stock_item_id', $items->pluck('id'))
            ->get(['t.*', 's.company_name as supplier'])->groupBy('stock_item_id');
        $z = self::Z[$serviceLevel] ?? 1.65;
        $out = [];

        foreach ($items as $item) {
            $p = $profiles[$item->id] ?? null;
            $pp = $params[$item->id] ?? null;
            $set = $rows[$item->id] ?? collect();
            $atp = (float) $set->sum('available_to_promise');
            $onOrder = (float) ($set->first()['expected_receipts'] ?? 0);
            $back = (float) $set->sum('backordered');
            $reserved = (float) $set->sum('reserved');
            $position = round($atp + $onOrder - $back, 4);

            $f = $this->forecaster->dailyRate($item->id);
            [$daily, $demandSource] = $f !== null
                ? [$f['daily'], $f['overridden'] ? 'Forecast (overridden by a planner)' : "Forecast ({$f['method']})"]
                : [(float) ($history[$item->id] ?? 0), 'Average of the last 90 days (no forecast)'];

            $term = $this->chooseTerms($terms[$item->id] ?? collect(), $pp?->preferred_supplier_id);
            [$lead, $leadSource] = match (true) {
                $term?->lead_time_days !== null && $term !== null => [(int) $term->lead_time_days, "{$term->supplier}'s terms"],
                (int) ($p?->lead_time_days ?? 0) > 0 => [(int) $p->lead_time_days, 'Product profile'],
                (int) ($pp?->lead_time_days ?? 0) > 0 => [(int) $pp->lead_time_days, 'Planning parameters'],
                default => [0, 'Not set: ordering assumes immediate delivery'],
            };
            [$safety, $safetySource] = match (true) {
                (float) ($p?->safety_stock ?? 0) > 0 => [(float) $p->safety_stock, 'Product profile'],
                (float) ($pp?->safety_stock ?? 0) > 0 => [(float) $pp->safety_stock, 'Planning parameters'],
                ($f['spread_daily'] ?? null) !== null && $lead > 0 => [round($z * ($f['spread_daily'] / 1.28) * sqrt($lead), 4), "Computed: {$serviceLevel}% service level × forecast spread × √lead time"],
                default => [0.0, 'None set'],
            };
            $reorderPoint = max((float) $item->reorder_level, $safety + $daily * $lead);
            if ($reorderPoint <= 0 || $position > $reorderPoint + 1e-6) {
                continue;
            }
            $cover = max($lead, 30);
            $target = (float) ($p?->max_stock ?? 0) > 0 ? (float) $p->max_stock : $reorderPoint + $daily * $cover;
            $need = max(0.0, $target - $position);
            $moq = (float) ($term?->min_order_qty ?? $pp?->min_order_qty ?? 0);
            $multiple = (float) ($term?->order_multiple ?? $pp?->order_multiple ?? 0);
            $qty = max($moq, $multiple > 0 ? ceil($need / $multiple - 1e-9) * $multiple : $need, (float) ($p?->reorder_qty ?? 0));

            $out[] = [
                'item_id' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'uom' => $item->uom, 'suggested_qty' => round($qty, 4),
                'supplier' => $term?->supplier, 'unit_cost' => (float) ($term?->unit_price ?? ($item->avg_cost ?: $item->standard_cost)),
                'days_of_cover' => $daily > 0 ? round($atp / $daily, 1) : null,
                'inputs' => [
                    ['label' => 'Available to promise', 'value' => $atp, 'source' => 'Stock in usable states, less reservations ('.round($reserved, 4).' reserved)'],
                    ['label' => 'On order', 'value' => $onOrder, 'source' => 'Open purchase orders not yet received'],
                    ['label' => 'Backordered', 'value' => $back, 'source' => 'Customer demand already promised and unfilled'],
                    ['label' => 'Stock position', 'value' => $position, 'source' => 'Available + on order − backordered'],
                    ['label' => 'Daily demand', 'value' => round($daily, 4), 'source' => $demandSource],
                    ['label' => 'Lead time (days)', 'value' => $lead, 'source' => $leadSource],
                    ['label' => 'Safety stock', 'value' => round($safety, 4), 'source' => $safetySource],
                    ['label' => 'Reorder point', 'value' => round($reorderPoint, 4), 'source' => 'Safety stock + daily demand × lead time (or the item\'s reorder level, if higher)'],
                    ['label' => 'Order up to', 'value' => round($target, 4), 'source' => (float) ($p?->max_stock ?? 0) > 0 ? 'Product maximum' : 'Reorder point + '.$cover.' days of demand'],
                    ['label' => 'Minimum order / multiple', 'value' => $moq ?: null, 'source' => $multiple ? "Multiples of {$multiple}" : ($moq ? 'Minimum order' : 'None')],
                ],
                'assumptions' => array_values(array_filter([
                    $f === null ? 'No forecast has been run for this item; demand is the recent average and ignores trends and seasons.' : null,
                    $lead === 0 ? 'No lead time is recorded, so stock is assumed to arrive the day it is ordered.' : null,
                    $daily <= 0 ? 'No demand is recorded; the suggestion only restores the reorder level.' : null,
                ])),
            ];
        }

        return collect($out)->sortBy(fn ($r) => $r['days_of_cover'] ?? PHP_INT_MAX)->values()->all();
    }

    /**
     * Suppliers side by side for one item, in base units: price paid (converted from the unit it was
     * bought in), landed cost per unit where landed costs were posted, delivery and quality record,
     * lead time and order terms. The ranking is by cost to have the goods here, with every factor shown.
     *
     * @return list<array<string,mixed>>
     */
    public function compareSuppliers(FinStockItem $item, ?Carbon $since = null): array
    {
        $since ??= now()->subYear();
        $lines = DB::table('purchase_order_items as i')->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')
            ->where('i.stock_item_id', $item->id)->whereNull('p.deleted_at')->where('p.order_date', '>=', $since->toDateString())
            ->get(['p.supplier_id', 'p.po_number', 'p.order_date', 'i.unit_price', 'i.quantity', 'i.uom_id']);
        $perBase = $lines->map(function ($l) use ($item) {
            try {
                $factor = $this->units->normalise($item, 1, $l->uom_id)['base_qty'];
            } catch (\RuntimeException) {
                return null; // a unit with no conversion cannot be compared fairly; it is left out, not guessed
            }

            return $factor > 0 ? ['supplier_id' => $l->supplier_id, 'price' => (float) $l->unit_price / $factor, 'qty' => (float) $l->quantity * $factor] : null;
        })->filter();
        $landed = DB::table('wms_landed_cost_lines as ll')->join('wms_landed_costs as lc', 'lc.id', '=', 'll.landed_cost_id')
            ->join('goods_received_notes as g', 'g.id', '=', 'lc.grn_id')->join('purchase_orders as p', 'p.id', '=', 'g.purchase_order_id')
            ->where('ll.item_id', $item->id)->where('lc.status', 'POSTED')->groupBy('p.supplier_id')
            ->selectRaw('p.supplier_id, SUM(ll.allocated) / NULLIF(SUM(ll.qty), 0) AS per_unit')->pluck('per_unit', 'supplier_id');
        $scores = collect(app(SupplierScorecard::class)->scores($since, now()))->keyBy('supplier_id');
        $terms = DB::table('wms_supplier_terms')->where('stock_item_id', $item->id)->get()->keyBy('supplier_id');
        $ids = $perBase->pluck('supplier_id')->merge($terms->keys())->unique()->filter();
        $names = DB::table('suppliers')->whereIn('id', $ids)->pluck('company_name', 'id');
        $insight = collect(app(SupplierInsight::class)->prices($item->id))->keyBy('supplier_id');

        return $ids->map(function ($sid) use ($perBase, $landed, $scores, $terms, $names, $insight) {
            $mine = $perBase->where('supplier_id', $sid);
            $qty = $mine->sum('qty');
            $price = $qty > 0 ? $mine->sum(fn ($m) => $m['price'] * $m['qty']) / $qty : (isset($terms[$sid]) ? (float) $terms[$sid]->unit_price : null);
            $land = isset($landed[$sid]) ? (float) $landed[$sid] : null;
            $s = $scores[$sid] ?? [];

            return [
                'supplier_id' => (int) $sid, 'supplier' => $names[$sid] ?? '#'.$sid, 'orders' => $mine->count(),
                'price_per_unit' => $price !== null ? round($price, 4) : null, 'landed_per_unit' => $land !== null ? round($land, 4) : null,
                'cost_here' => $price !== null ? round($price + ($land ?? 0), 4) : null,
                'on_time_rate' => $s['on_time_rate'] ?? null, 'quantity_accuracy' => $s['quantity_accuracy'] ?? null,
                'rejection_rate' => $s['rejection_rate'] ?? null, 'inspection_pass_rate' => $s['inspection_pass_rate'] ?? null,
                'lead_days' => $terms[$sid]->lead_time_days ?? ($insight[$sid]['actual_lead_days'] ?? ($s['avg_lead_time_days'] ?? null)),
                'min_order_qty' => isset($terms[$sid]) && $terms[$sid]->min_order_qty !== null ? (float) $terms[$sid]->min_order_qty : null,
                'price_trend' => $insight[$sid]['change_percent'] ?? null,
                'notes' => array_values(array_filter([$land === null ? 'No landed costs posted for this supplier: freight and duty are not in the comparison.' : null,
                    $mine->isEmpty() ? 'No purchases in the period; price is from agreed terms.' : null])),
            ];
        })->sortBy(fn ($r) => $r['cost_here'] ?? PHP_INT_MAX)->values()->all();
    }

    private function chooseTerms($terms, ?int $preferred)
    {
        return $terms->firstWhere('supplier_id', $preferred) ?? $terms->sortBy('unit_price')->first();
    }

    private function historyDaily(array $itemIds): array
    {
        return DB::table('fin_stock_movements')->whereIn('item_id', $itemIds)->where('qty', '<', 0)->whereIn('type', ['issue', 'sale', 'mfg_issue', 'dispatch'])
            ->whereNull('reversal_of_id')->where('created_at', '>=', now()->subDays(90))
            ->groupBy('item_id')->selectRaw('item_id, -SUM(qty) / 90.0 AS daily')->pluck('daily', 'item_id')->map(fn ($v) => (float) $v)->all();
    }
}
