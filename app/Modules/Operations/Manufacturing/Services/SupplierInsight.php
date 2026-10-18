<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Nonconformance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier performance for planning and buying (MFG-015): how reliably each supplier delivers, how
 * often their material fails inspection, what they charge for each material now against before,
 * which orders are late or at risk, and who else could supply.
 *
 * Read from procurement's purchase orders and goods received notes and from this module's
 * inspections and non-conformances -- nothing is copied.
 */
class SupplierInsight
{
    /** One row per supplier with orders or inspections in the period. */
    public function scorecard(Carbon $from, Carbon $to): Collection
    {
        $orders = DB::table('purchase_orders')->whereNull('deleted_at')->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'supplier_id', 'expected_delivery', 'delivered_at', 'status']);
        $inspections = Inspection::query()->where('stage', 'INCOMING')->whereNotNull('supplier_id')->whereBetween('created_at', [$from, $to])->get(['supplier_id', 'result']);
        $ncrs = Nonconformance::query()->whereNotNull('supplier_id')->whereBetween('created_at', [$from, $to])->get(['supplier_id']);
        $ids = $orders->pluck('supplier_id')->merge($inspections->pluck('supplier_id'))->merge($ncrs->pluck('supplier_id'))->filter()->unique();
        $names = DB::table('suppliers')->whereIn('id', $ids)->pluck('company_name', 'id');

        return $ids->map(function ($id) use ($orders, $inspections, $ncrs, $names) {
            $mine = $orders->where('supplier_id', $id);
            $delivered = $mine->filter(fn ($o) => $o->delivered_at && $o->expected_delivery);
            $onTime = $delivered->filter(fn ($o) => Carbon::parse($o->delivered_at)->startOfDay()->lte(Carbon::parse($o->expected_delivery)->endOfDay()));
            $late = $mine->filter(fn ($o) => ! $o->delivered_at && $o->expected_delivery && Carbon::parse($o->expected_delivery)->endOfDay()->isPast()
                && ! in_array($o->status, ['cancelled', 'closed', 'received', 'completed'], true));
            $checked = $inspections->where('supplier_id', $id)->whereIn('result', ['PASSED', 'FAILED']);

            return [
                'supplier_id' => (int) $id, 'supplier' => $names[$id] ?? '—',
                'orders' => $mine->count(), 'delivered' => $delivered->count(),
                'on_time_percent' => $delivered->count() ? round($onTime->count() / $delivered->count() * 100, 1) : null,
                'late_open' => $late->count(),
                'inspections' => $checked->count(),
                'failure_percent' => $checked->count() ? round($checked->where('result', 'FAILED')->count() / $checked->count() * 100, 1) : null,
                'ncrs' => $ncrs->where('supplier_id', $id)->count(),
            ];
        })->sortBy([['failure_percent', 'desc'], ['on_time_percent', 'asc']])->values();
    }

    /**
     * What each supplier has charged for a material: the latest price, the average over the year
     * before it, and the change -- with lead time actually achieved.
     */
    public function prices(int $stockItemId): Collection
    {
        if (! Schema::hasColumn('purchase_order_items', 'stock_item_id')) {
            return collect();
        }

        $lines = DB::table('purchase_order_items as i')->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')
            ->where('i.stock_item_id', $stockItemId)->whereNull('p.deleted_at')->orderBy('p.order_date')
            ->get(['p.supplier_id', 'p.po_number', 'p.order_date', 'p.expected_delivery', 'p.delivered_at', 'i.unit_price', 'i.quantity']);
        $names = DB::table('suppliers')->whereIn('id', $lines->pluck('supplier_id')->unique())->pluck('company_name', 'id');

        return $lines->groupBy('supplier_id')->map(function ($g, $supplierId) use ($names) {
            $last = $g->last();
            $yearBefore = $g->filter(fn ($l) => $l !== $last && Carbon::parse($l->order_date)->gte(Carbon::parse($last->order_date)->subYear()));
            $avg = $yearBefore->isNotEmpty() ? $yearBefore->avg(fn ($l) => (float) $l->unit_price) : null;
            $leads = $g->filter(fn ($l) => $l->delivered_at && $l->order_date)->map(fn ($l) => Carbon::parse($l->order_date)->diffInDays(Carbon::parse($l->delivered_at)));

            return [
                'supplier_id' => (int) $supplierId, 'supplier' => $names[$supplierId] ?? '—', 'orders' => $g->count(),
                'last_price' => (float) $last->unit_price, 'last_order' => $last->po_number, 'last_date' => $last->order_date,
                'year_average' => $avg !== null ? round($avg, 4) : null,
                'change_percent' => $avg ? round(((float) $last->unit_price - $avg) / $avg * 100, 1) : null,
                'actual_lead_days' => $leads->isNotEmpty() ? round($leads->avg(), 1) : null,
            ];
        })->sortBy('last_price')->values();
    }

    /** Open purchase orders that are late, or arrive after the plan needs them. */
    public function deliveryRisks(?array $needDates = null): Collection
    {
        $open = DB::table('purchase_orders as p')->whereNull('p.deleted_at')->whereNull('p.delivered_at')
            ->whereNotIn('p.status', ['draft', 'cancelled', 'closed', 'rejected', 'received', 'completed'])
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->get(['p.id', 'p.po_number', 'p.expected_delivery', 'p.order_date', 's.company_name as supplier']);

        $byItem = Schema::hasColumn('purchase_order_items', 'stock_item_id')
            ? DB::table('purchase_order_items')->whereIn('purchase_order_id', $open->pluck('id'))->whereNotNull('stock_item_id')->get(['purchase_order_id', 'stock_item_id'])->groupBy('purchase_order_id')
            : collect();

        return $open->map(function ($po) use ($byItem, $needDates) {
            $expected = $po->expected_delivery ? Carbon::parse($po->expected_delivery) : null;
            $reasons = [];
            if (! $expected) {
                $reasons[] = 'No expected delivery date.';
            } elseif ($expected->endOfDay()->isPast()) {
                $reasons[] = sprintf('%d day(s) late.', (int) $expected->diffInDays(now()));
            }
            foreach (($byItem[$po->id] ?? collect()) as $line) {
                $need = $needDates[$line->stock_item_id] ?? null;
                if ($need && $expected && $expected->gt(Carbon::parse($need))) {
                    $reasons[] = "Arrives after the plan needs it ({$need}).";
                }
            }

            return $reasons ? ['po' => $po->po_number, 'supplier' => $po->supplier, 'expected' => $po->expected_delivery, 'reasons' => array_values(array_unique($reasons))] : null;
        })->filter()->values();
    }

    /** Approved substitutes for a material, where a product's BOM allows one. */
    public function substitutes(int $stockItemId): Collection
    {
        return DB::table('mfg_item_substitutes as x')->join('fin_stock_items as s', 's.id', '=', 'x.substitute_stock_item_id')
            ->join('mfg_items as m', 'm.id', '=', 'x.item_id')
            ->where('m.stock_item_id', $stockItemId)->where('x.is_active', true)
            ->where(fn ($q) => $q->where('x.requires_approval', false)->orWhereNotNull('x.approved_at'))
            ->get(['s.id', 's.code', 's.name', 'x.ratio'])->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'ratio' => (float) $r->ratio]);
    }
}
