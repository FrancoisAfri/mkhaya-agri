<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Facades\DB;

/**
 * Excess and obsolete stock (WMS-122): which stock is not moving, moving too slowly for what is
 * held, or no longer wanted -- by thresholds the company sets -- what it is worth, and what can be
 * done that does not need writing it off: move it to where it sells, stop buying more of it.
 */
class ExcessObsolete
{
    use ScopesRawQueries;

    public const DEFAULTS = ['non_moving_days' => 180, 'obsolete_days' => 365, 'slow_cover_months' => 6];

    /** @param array{non_moving_days?:int, obsolete_days?:int, slow_cover_months?:int} $t */
    public function analyse(array $t = []): array
    {
        $t += self::DEFAULTS;
        $cid = $this->tenantId();
        $cost = "CASE WHEN COALESCE(i.is_valued, true) THEN (CASE i.cost_method WHEN 'standard' THEN i.standard_cost ELSE i.avg_cost END) ELSE 0 END";
        $lastOut = DB::table('fin_stock_movements')->where('qty', '<', 0)->whereIn('type', ['dispatch', 'sale', 'issue', 'mfg_issue'])->whereNull('reversal_of_id')
            ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, MAX(movement_date) AS last_out');
        $used = DB::table('fin_stock_movements')->where('qty', '<', 0)->whereIn('type', ['dispatch', 'sale', 'issue', 'mfg_issue'])->whereNull('reversal_of_id')
            ->where('movement_date', '>=', now()->subDays(90)->toDateString())->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, SUM(ABS(qty)) / 3.0 AS monthly');

        $rows = DB::table('fin_stock_balances as b')->join('fin_stock_items as i', 'i.id', '=', 'b.item_id')->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoinSub($lastOut, 'o', fn ($j) => $j->on('o.item_id', '=', 'b.item_id')->on('o.warehouse_id', '=', 'b.warehouse_id'))
            ->leftJoinSub($used, 'u', fn ($j) => $j->on('u.item_id', '=', 'b.item_id')->on('u.warehouse_id', '=', 'b.warehouse_id'))
            ->when($cid, fn ($q, $c) => $q->where('b.company_id', $c))->where('b.qty', '>', 0)
            ->get(['b.item_id', 'b.warehouse_id', 'i.code', 'i.name', 'i.uom', 'i.status', 'w.code as warehouse', 'b.qty', 'o.last_out', 'u.monthly',
                DB::raw("{$cost} AS unit_cost"), DB::raw('i.created_at AS item_created')]);

        $openPo = DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereNull('po.deleted_at')->where('po.status', '!=', 'cancelled')->whereNotNull('poi.stock_item_id')
            ->where(fn ($q) => $q->whereNull('po.received_status')->orWhere('po.received_status', '!=', 'received'))
            ->when($cid, fn ($q, $c) => $q->where('po.company_id', $c))
            ->groupBy('poi.stock_item_id')->selectRaw("poi.stock_item_id, SUM(poi.quantity) AS qty, string_agg(DISTINCT po.po_number, ', ') AS orders")->get()->keyBy('stock_item_id');

        $out = [];
        foreach ($rows as $r) {
            $daysIdle = $r->last_out ? (int) now()->parse($r->last_out)->diffInDays(now()) : (int) now()->parse($r->item_created)->diffInDays(now());
            $monthly = (float) ($r->monthly ?? 0);
            $cover = $monthly > 0 ? (float) $r->qty / $monthly : null;
            $class = match (true) {
                in_array($r->status, [FinStockItem::OBSOLETE, FinStockItem::DISCONTINUED], true) || $daysIdle >= $t['obsolete_days'] => 'OBSOLETE',
                $daysIdle >= $t['non_moving_days'] => 'NON_MOVING',
                $cover !== null && $cover > $t['slow_cover_months'] => 'SLOW',
                default => null,
            };
            if ($class === null) {
                continue;
            }
            $excessQty = $class === 'SLOW' ? round((float) $r->qty - $monthly * $t['slow_cover_months'], 4) : (float) $r->qty;
            $out[] = ['item_id' => (int) $r->item_id, 'item' => $r->code, 'name' => $r->name, 'uom' => $r->uom, 'warehouse_id' => (int) $r->warehouse_id,
                'warehouse' => $r->warehouse, 'class' => $class, 'qty' => (float) $r->qty, 'last_out' => $r->last_out, 'days_idle' => $daysIdle,
                'monthly_use' => round($monthly, 4), 'months_cover' => $cover !== null ? round($cover, 1) : null, 'excess_qty' => $excessQty,
                'excess_value' => round($excessQty * (float) $r->unit_cost, 2), 'actions' => []];
        }

        // Where it sells instead: the same item used elsewhere with less than a few months' cover.
        $byItem = collect($rows)->groupBy('item_id');
        foreach ($out as &$row) {
            foreach ($byItem[$row['item_id']] ?? [] as $other) {
                $m = (float) ($other->monthly ?? 0);
                if ((int) $other->warehouse_id !== $row['warehouse_id'] && $m > 0 && (float) $other->qty / $m < 2) {
                    $row['actions'][] = ['kind' => 'TRANSFER', 'text' => "Transfer to {$other->warehouse}, which uses ".round($m, 1)." {$row['uom']} a month and holds ".round((float) $other->qty / $m, 1).' months.'];
                }
            }
            if (isset($openPo[$row['item_id']])) {
                $row['actions'][] = ['kind' => 'REDUCE_PURCHASING', 'text' => 'Reduce or cancel what is still on order ('.$openPo[$row['item_id']]->orders.': '.round((float) $openPo[$row['item_id']]->qty, 4).').'];
            }
            if ($row['class'] === 'OBSOLETE') {
                $row['actions'][] = ['kind' => 'DISPOSE', 'text' => 'Sell off, return to the supplier or write down -- each through its own approval.'];
            }
        }
        unset($row);

        $collection = collect($out);

        return ['thresholds' => $t, 'rows' => $collection->sortByDesc('excess_value')->values()->all(),
            'totals' => collect(['OBSOLETE', 'NON_MOVING', 'SLOW'])->mapWithKeys(fn ($c) => [$c => ['items' => $collection->where('class', $c)->count(),
                'value' => round($collection->where('class', $c)->sum('excess_value'), 2)]])->all()];
    }
}
