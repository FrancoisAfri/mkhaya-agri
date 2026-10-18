<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Operations\Manufacturing\Models\Forecast;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demand and production planning views (MFG-004): forecast against confirmed orders against stock
 * per product and month; production by status; orders in priority order under a chosen rule; and
 * work-centre load against capacity, week by week, so bottlenecks show before they bite.
 *
 * A forecast is shown beside confirmed orders, never added to them: the projection plans for
 * whichever is larger in each month.
 */
class DemandPlanning
{
    public const RULES = ['DUE_DATE', 'PRIORITY', 'SHORTEST_FIRST', 'CUSTOMER_FIRST'];

    public function __construct(private readonly FactoryCalendar $calendar) {}

    /** @return list<array> one row per product with a month-by-month view */
    public function products(int $months = 6): array
    {
        $start = now()->startOfMonth();
        $monthKeys = collect(range(0, $months - 1))->map(fn ($i) => $start->copy()->addMonths($i)->format('Y-m'))->all();
        $items = Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)->with('stockItem:id,code,name')->get();

        $forecasts = Forecast::query()->where('status', 'APPROVED')->where('period_start', '>=', $start)->get()
            ->groupBy(fn ($f) => $f->stock_item_id.'|'.$f->period_start->format('Y-m'))->map(fn ($g) => (float) $g->sum('qty'));
        $orders = DB::table('fin_sales_order_lines as l')->join('fin_sales_orders as o', 'o.id', '=', 'l.order_id')
            ->join('fin_items as fi', 'fi.id', '=', 'l.item_id')
            ->join('fin_stock_items as s', fn ($j) => $j->on('s.code', '=', 'fi.code')->on('s.company_id', '=', 'o.company_id'))
            ->whereIn('o.status', ['open', 'part_delivered'])->whereRaw('l.quantity - l.qty_delivered > 0')
            ->get(['s.id as stock_item_id', 'o.order_date', DB::raw('l.quantity - l.qty_delivered as qty')])
            ->groupBy(fn ($r) => $r->stock_item_id.'|'.max(Carbon::parse($r->order_date)->format('Y-m'), $start->format('Y-m')))
            ->map(fn ($g) => (float) $g->sum('qty'));
        $production = PO::query()->whereNotIn('status', [PO::CLOSED, PO::CANCELLED, PO::REJECTED, PO::COMPLETED])->with('item:id,stock_item_id')->get()
            ->groupBy(fn ($o) => $o->item?->stock_item_id.'|'.max($o->due_date->format('Y-m'), $start->format('Y-m')))
            ->map(fn ($g) => (float) $g->sum(fn ($o) => (float) $o->planned_qty - (float) $o->qty_completed));

        return $items->map(function (Item $item) use ($monthKeys, $forecasts, $orders, $production) {
            $sid = $item->stock_item_id;
            $onHand = (float) FinStockPosition::withoutGlobalScopes()->where('item_id', $sid)->where('status', FinStockPosition::AVAILABLE)->sum('qty');
            $projected = $onHand;
            $rows = [];
            foreach ($monthKeys as $m) {
                $f = $forecasts["{$sid}|{$m}"] ?? 0.0;
                $o = $orders["{$sid}|{$m}"] ?? 0.0;
                $p = $production["{$sid}|{$m}"] ?? 0.0;
                $projected += $p - max($f, $o);
                $rows[] = ['month' => $m, 'forecast' => $f, 'orders' => $o, 'production' => $p, 'projected' => round($projected, 4),
                    'uncovered_forecast' => round(max($f - $o, 0), 4)];
            }

            return ['id' => $item->uuid, 'stock_item_id' => $sid, 'code' => $item->stockItem?->code, 'name' => $item->stockItem?->name,
                'on_hand' => round($onHand, 4), 'months' => $rows,
                'short_in' => collect($rows)->first(fn ($r) => $r['projected'] < 0)['month'] ?? null];
        })->filter(fn ($r) => collect($r['months'])->sum(fn ($m) => $m['forecast'] + $m['orders'] + $m['production']) > 0 || $r['on_hand'] > 0)
            ->values()->all();
    }

    /** Planned, confirmed, released, completed and overdue production. */
    public function statusSummary(): array
    {
        $open = PO::query()->whereNotIn('status', [PO::CANCELLED, PO::REJECTED])->get(['id', 'status', 'due_date', 'completed_at']);

        return [
            'planned' => $open->whereIn('status', [PO::DRAFT, PO::PLANNED])->count(),
            'confirmed' => $open->where('status', PO::APPROVED)->count(),
            'released' => $open->whereIn('status', [PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW])->count(),
            'completed' => $open->whereIn('status', [PO::COMPLETED, PO::CLOSED])->filter(fn ($o) => $o->completed_at?->gte(now()->subDays(30)))->count(),
            'overdue' => $open->filter(fn ($o) => $o->isOverdue())->count(),
            'on_hold' => $open->where('status', PO::ON_HOLD)->count(),
        ];
    }

    /** Open orders in the order the chosen rule would work them. */
    public function prioritised(string $rule): Collection
    {
        $orders = PO::query()->whereIn('status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
            ->with(['item.stockItem:id,code,name', 'operations'])->get();

        $sorted = match ($rule) {
            'PRIORITY' => $orders->sortBy([['priority', 'asc'], ['due_date', 'asc']]),
            'SHORTEST_FIRST' => $orders->sortBy(fn ($o) => $o->operations->where('status', '!=', Op::COMPLETED)->sum('planned_minutes')),
            // Customer orders before stock replenishment, then by date.
            'CUSTOMER_FIRST' => $orders->sortBy([fn ($a, $b) => (int) ($b->demand_type === 'SALES_ORDER') <=> (int) ($a->demand_type === 'SALES_ORDER'), ['due_date', 'asc']]),
            default => $orders->sortBy([['due_date', 'asc'], ['priority', 'asc']]),
        };

        return $sorted->values()->map(fn (PO $o, $i) => [
            'rank' => $i + 1, 'id' => $o->uuid, 'number' => $o->number, 'status' => $o->status, 'priority' => (int) $o->priority,
            'product' => trim(($o->item?->stockItem?->code ?? '').' — '.($o->item?->stockItem?->name ?? '')),
            'due_date' => $o->due_date?->toDateString(), 'is_overdue' => $o->isOverdue(), 'demand_type' => $o->demand_type,
            'remaining_minutes' => round($o->operations->where('status', '!=', Op::COMPLETED)->sum('planned_minutes'), 1),
        ]);
    }

    /**
     * Load against capacity per work centre per week: the remaining planned minutes of open orders
     * in the week they are planned to start, against the factory calendar's shift time multiplied
     * by the centre's concurrent jobs. Over 100% is a bottleneck.
     */
    public function capacity(int $weeks = 6): array
    {
        $start = now()->startOfWeek();
        $centres = WorkCentre::query()->where('is_active', true)->with('factory')->orderBy('code')->get();
        $load = Op::query()->join('mfg_production_orders as o', 'o.id', '=', 'mfg_production_order_operations.order_id')
            ->whereIn('o.status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
            ->whereNotIn('mfg_production_order_operations.status', [Op::COMPLETED, Op::SKIPPED])
            ->where('mfg_production_order_operations.is_subcontracted', false)
            ->get(['mfg_production_order_operations.work_centre_id', 'mfg_production_order_operations.planned_minutes', 'o.planned_start', 'o.due_date']);

        return $centres->map(function (WorkCentre $c) use ($start, $weeks, $load) {
            $rows = [];
            for ($w = 0; $w < $weeks; $w++) {
                $from = $start->copy()->addWeeks($w);
                $to = $from->copy()->endOfWeek();
                $available = $this->calendar->minutesBetween($c->factory, $from, $to, $c->production_line_id) * max(1, (int) $c->concurrent_jobs);
                $minutes = $load->where('work_centre_id', $c->id)->filter(function ($l) use ($from, $to, $w) {
                    $when = Carbon::parse($l->planned_start ?? $l->due_date);

                    return $w === 0 ? $when->lte($to) : $when->between($from, $to);
                })->sum('planned_minutes');
                $rows[] = ['week' => $from->toDateString(), 'load' => round((float) $minutes, 1), 'available' => (float) $available,
                    'percent' => $available > 0 ? round($minutes / $available * 100, 1) : null, 'overloaded' => $available > 0 ? $minutes > $available : $minutes > 0];
            }

            return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'weeks' => $rows,
                'bottleneck' => collect($rows)->contains('overloaded', true)];
        })->values()->all();
    }
}
