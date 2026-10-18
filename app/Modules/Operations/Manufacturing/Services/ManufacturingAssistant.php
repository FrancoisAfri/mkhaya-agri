<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\OrderVariance;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog as R;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The figures behind the Mkhaya AI manufacturing assistant (MFG-019).
 *
 * Every answer is computed here, by code, from the company's own records -- the same tables the
 * screens read, under the same row-level security. Each figure carries what kind of figure it is
 * (ACTUAL from posted records, FORECAST from the schedule, ESTIMATE where something is assumed),
 * the period, and the records it came from. Problems with the data are flagged rather than worked
 * around. Recommendations are labelled as such and point at the record to act on.
 *
 * A language model never produces a figure: AssistantNarrator may only word what is here.
 */
class ManufacturingAssistant
{
    /** topic => [question it answers, permissions (any one suffices)] */
    public const TOPICS = [
        'material_variance' => ['Which products had the highest material cost variance last month?', [R::VIEW_COSTS]],
        'late_orders' => ['Which production orders are likely to miss their due dates?', [R::VIEW_ORDERS]],
        'downtime' => ['Which machines caused the most production downtime?', [R::VIEW_MAINTENANCE, R::SUPERVISE_FLOOR]],
        'materials_at_risk' => ['Which materials are at risk of running out?', [R::VIEW_PLANNING]],
        'supplier_quality' => ['Which suppliers have the highest rate of quality failures?', [R::VIEW_QUALITY]],
        'scrap_causes' => ['What are the main causes of scrap and rework?', [R::VIEW_QUALITY, R::VIEW_COSTS]],
        'declining_margins' => ['Which products have declining margins?', [R::VIEW_COSTS]],
        'cost_reduction' => ['What actions could reduce our production costs?', [R::VIEW_COSTS]],
    ];

    private const KEYWORDS = [
        'supplier_quality' => ['supplier', 'vendor'],
        'materials_at_risk' => ['run out', 'running out', 'stock out', 'stockout', 'shortage', 'at risk of', 'enough material'],
        'material_variance' => ['material cost variance', 'material variance', 'material cost', 'usage variance', 'price variance'],
        'late_orders' => ['miss', 'late', 'due date', 'behind schedule', 'deadline', 'on time'],
        'downtime' => ['downtime', 'breakdown', 'stoppage', 'machines', 'machine'],
        'scrap_causes' => ['scrap', 'rework', 'defect', 'reject'],
        'declining_margins' => ['margin', 'profitab'],
        'cost_reduction' => ['reduce', 'saving', 'cheaper', 'lower cost', 'cut cost', 'production costs'],
    ];

    public function __construct(
        private readonly ProductionOrderLifecycle $lifecycle,
        private readonly Profitability $profitability,
        private readonly \App\Modules\Finance\Accounting\Services\StockLedger $stock,
    ) {}

    public function topicFor(string $question): ?string
    {
        $q = mb_strtolower($question);
        foreach (self::KEYWORDS as $topic => $words) {
            foreach ($words as $w) {
                if (str_contains($q, $w)) {
                    return $topic;
                }
            }
        }

        return null;
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function periodFor(string $question, string $topic): array
    {
        $q = mb_strtolower($question);
        $today = today();

        return match (true) {
            str_contains($q, 'last month') => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'last month'],
            str_contains($q, 'this month') => [$today->copy()->startOfMonth(), $today->copy()->endOfDay(), 'this month'],
            str_contains($q, 'last week') => [$today->copy()->subWeek()->startOfWeek(), $today->copy()->subWeek()->endOfWeek(), 'last week'],
            str_contains($q, 'this week') => [$today->copy()->startOfWeek(), $today->copy()->endOfDay(), 'this week'],
            str_contains($q, 'last quarter') => [$today->copy()->subQuarterNoOverflow()->startOfQuarter(), $today->copy()->subQuarterNoOverflow()->endOfQuarter(), 'last quarter'],
            str_contains($q, 'this year'), str_contains($q, 'year to date') => [$today->copy()->startOfYear(), $today->copy()->endOfDay(), 'this year'],
            (bool) preg_match('/last (\d{1,3}) (day|week|month)s?/', $q, $m) => [$today->copy()->sub($m[2], (int) $m[1])->startOfDay(), $today->copy()->endOfDay(), "last {$m[1]} {$m[2]}s"],
            // The question as the spec asks it names last month; the others look at the last 30 days.
            $topic === 'material_variance' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth(), 'last month'],
            $topic === 'declining_margins' => [$today->copy()->subDays(89)->startOfDay(), $today->copy()->endOfDay(), 'last 90 days'],
            default => [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay(), 'last 30 days'],
        };
    }

    public function allowed(User $user, string $topic): bool
    {
        foreach (self::TOPICS[$topic][1] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{topic: ?string, title: string, refused: bool, period: ?array, rows: list<array>, columns: list<string>,
     *               sources: list<string>, flags: list<array{level: string, message: string}>, recommendations: list<array>, summary: string}
     */
    public function answer(string $question, User $user): array
    {
        $topic = $this->topicFor($question);
        if (! $topic) {
            return $this->empty(null, 'I can answer these questions from your records: '.implode(' ', array_column(self::TOPICS, 0)));
        }
        if (! $this->allowed($user, $topic)) {
            return $this->empty($topic, 'Your permissions do not cover the records this question needs, so no figures are shown.', true);
        }
        [$from, $to, $label] = $this->periodFor($question, $topic);
        $method = lcfirst(str_replace('_', '', ucwords($topic, '_')));
        $a = $this->{$method}($from, $to, $user);

        return $a + ['topic' => $topic, 'title' => self::TOPICS[$topic][0], 'refused' => false,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label]];
    }

    // ── Topics ───────────────────────────────────────────────────────────────────────────────

    private function materialVariance(Carbon $from, Carbon $to): array
    {
        $v = OrderVariance::query()->whereIn('kind', ['MATERIAL_USAGE', 'MATERIAL_PRICE', 'TOTAL'])->whereBetween('calculated_at', [$from, $to->copy()->endOfDay()])
            ->with('order.item.stockItem')->get();
        $insufficient = $v->where('kind', 'TOTAL')->where('data_quality', 'INSUFFICIENT')->count();
        $material = $v->whereIn('kind', ['MATERIAL_USAGE', 'MATERIAL_PRICE'])->whereNotNull('amount');
        $rows = $material->groupBy(fn ($x) => $x->order?->item_id)->map(function ($g) {
            $item = $g->first()->order?->item;

            return ['product' => $item?->stockItem?->code, 'usage_variance' => round((float) $g->where('kind', 'MATERIAL_USAGE')->sum('amount'), 2),
                'price_variance' => round((float) $g->where('kind', 'MATERIAL_PRICE')->sum('amount'), 2), 'total' => round((float) $g->sum('amount'), 2),
                'orders' => $g->pluck('order_id')->unique()->count(), 'kind' => 'ACTUAL', 'item_id' => $item?->id,
                'url' => $item ? "/manufacturing/engineering/products/{$item->uuid}" : null,
                'records' => $g->pluck('order.number')->unique()->values()->all()];
        })->sortByDesc('total')->values();

        $flags = [];
        if ($rows->isEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'No production orders were closed against an approved standard cost in this period, so there is no material variance to report.');
        }
        if ($insufficient) {
            $flags[] = $this->flag('INSUFFICIENT', "{$insufficient} order(s) closed without an approved standard cost and are not included.");
        }
        $contradictions = $material->filter(fn ($x) => (float) $x->amount != 0.0 && (float) ($x->order?->act_material_cost ?? 0) == 0.0)->pluck('order.number')->unique();
        if ($contradictions->isNotEmpty()) {
            $flags[] = $this->flag('CONTRADICTORY', 'A material variance is recorded on '.$contradictions->implode(', ').' although no material cost is recorded on the order.');
        }
        $top = $rows->first(fn ($r) => $r['total'] > 0);

        return [
            'columns' => ['product', 'usage_variance', 'price_variance', 'total', 'orders'], 'rows' => $rows->take(10)->all(),
            'sources' => ["{$material->pluck('order_id')->unique()->count()} closed production orders' variance records (positive = over standard)"],
            'flags' => $flags,
            'recommendations' => $top ? [$this->rec("Investigate material use on {$top['product']}: {$this->money($top['total'])} over standard across {$top['orders']} order(s).",
                'PRODUCT', $top['item_id'], $top['product'], $top['url'], 'MATERIAL_VARIANCE', $top['total'], ['INVESTIGATION', 'TASK'], ['category' => 'EXCESS_MATERIAL'])] : [],
            'summary' => $top ? "{$top['product']} had the highest material cost variance: {$this->money($top['total'])} over standard ({$this->money($top['usage_variance'])} usage, {$this->money($top['price_variance'])} price)."
                : 'No product was over its standard material cost in this period.',
        ];
    }

    private function lateOrders(): array
    {
        $orders = PO::query()->whereIn('status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::ON_HOLD])
            ->with(['item.stockItem', 'operations'])->get();
        $unscheduled = 0;
        $contradictory = [];
        $rows = collect();
        foreach ($orders as $o) {
            $kind = 'FORECAST';
            $finish = $o->sched_finish ?? $o->planned_finish;
            if (! $finish) {
                $unscheduled++;
                $kind = 'ESTIMATE';
                $remaining = (float) $o->operations->whereNotIn('status', [Op::COMPLETED, Op::SKIPPED])->sum('planned_minutes');
                $finish = today()->addDays((int) ceil($remaining / 480));
            }
            $finish = Carbon::parse($finish)->startOfDay();
            $reasons = [];
            if ($o->due_date->lt(today())) {
                $reasons[] = 'already overdue';
            }
            if ($finish->gt($o->due_date)) {
                $reasons[] = 'finishes '.$finish->toDateString();
            }
            $short = in_array($o->status, [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION], true) ? $this->lifecycle->shortages($o) : [];
            if ($short) {
                $reasons[] = 'short of '.collect($short)->pluck('item')->implode(', ');
            }
            if ($o->status === PO::ON_HOLD) {
                $reasons[] = 'on hold';
            }
            $received = (float) DB::table('fin_stock_movements')->where('source_type', PO::SOURCE_TYPE)->where('source_id', $o->id)->where('type', 'mfg_receipt')->whereNull('reversal_of_id')->sum('qty');
            if (abs($received - (float) $o->qty_completed) > 0.0001) {
                $contradictory[] = $o->number;
            }
            if ($reasons) {
                $rows->push(['order' => $o->number, 'product' => $o->item?->stockItem?->code, 'due' => $o->due_date->toDateString(),
                    'projected_finish' => $finish->toDateString(), 'days_late' => max(0, (int) $o->due_date->diffInDays($finish->max(today()), false)),
                    'why' => implode('; ', $reasons), 'kind' => $kind, 'order_id' => $o->id, 'short' => $short,
                    'url' => "/manufacturing/production/orders/{$o->uuid}", 'records' => [$o->number]]);
            }
        }
        $rows = $rows->sortByDesc('days_late')->values();
        $flags = [];
        if ($unscheduled) {
            $flags[] = $this->flag('MISSING', "{$unscheduled} open order(s) have not been scheduled; their finish is estimated from the routing minutes left, at eight working hours a day.");
        }
        if ($contradictory) {
            $flags[] = $this->flag('CONTRADICTORY', 'Recorded output does not match the stock received on '.implode(', ', $contradictory).'.');
        }

        return [
            'columns' => ['order', 'product', 'due', 'projected_finish', 'days_late', 'why'], 'rows' => $rows->take(15)->all(),
            'sources' => ["{$orders->count()} open production orders, their schedule, routing and material shortages"],
            'flags' => $flags,
            'recommendations' => $rows->take(5)->map(fn ($r) => $r['short']
                ? $this->rec("Buy the material {$r['order']} is short of: ".collect($r['short'])->map(fn ($s) => "{$s['item']} {$this->num($s['short'])}")->implode(', ').'.',
                    'PRODUCTION_ORDER', $r['order_id'], $r['order'], $r['url'], 'ORDER_LATENESS', $r['days_late'], ['PURCHASE_REQUEST', 'TASK'],
                    ['items' => collect($r['short'])->map(fn ($s) => ['code' => $s['item'], 'qty' => $s['short']])->all()])
                : $this->rec("Bring {$r['order']} forward: due {$r['due']}, projected {$r['projected_finish']}.", 'PRODUCTION_ORDER', $r['order_id'], $r['order'], $r['url'],
                    'ORDER_LATENESS', $r['days_late'], ['PRIORITISE_ORDER', 'TASK'], ['order_id' => $r['order_id']]))->all(),
            'summary' => $rows->isEmpty() ? 'No open production order is projected to miss its due date.'
                : "{$rows->count()} order(s) are likely to miss their due date; the furthest behind is {$rows->first()['order']}, projected {$rows->first()['days_late']} day(s) late.",
        ];
    }

    private function downtime(Carbon $from, Carbon $to): array
    {
        $events = DowntimeEvent::query()->where('started_at', '<=', $to->copy()->endOfDay())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))->with(['reason', 'workCentre'])->get()
            ->reject(fn ($e) => $e->reason?->is_planned);
        $open = $events->whereNull('ended_at')->count();
        $minutes = fn ($e) => $e->ended_at ? (float) $e->minutes : $e->started_at->diffInMinutes(now());
        $rows = $events->groupBy('work_centre_id')->map(fn ($g) => [
            'work_centre' => $g->first()->workCentre?->code, 'minutes' => round($g->sum($minutes), 1), 'hours' => round($g->sum($minutes) / 60, 1), 'stops' => $g->count(),
            'main_reason' => $g->groupBy(fn ($e) => $e->reason?->name ?? '—')->map(fn ($x) => $x->sum($minutes))->sortDesc()->keys()->first(),
            'kind' => 'ACTUAL', 'centre_id' => $g->first()->work_centre_id, 'url' => '/manufacturing/floor/downtime',
            'records' => $g->pluck('uuid')->take(20)->all(),
        ])->sortByDesc('minutes')->values();
        $flags = [];
        if ($rows->isEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'No unplanned downtime was recorded in this period. If machines did stop, it was not recorded on the floor.');
        }
        if ($open) {
            $flags[] = $this->flag('MISSING', "{$open} stoppage(s) have not been ended; they are counted up to now.");
        }
        $top = $rows->first();

        return [
            'columns' => ['work_centre', 'hours', 'stops', 'main_reason'], 'rows' => $rows->take(10)->all(),
            'sources' => ["{$events->count()} unplanned downtime events recorded on the shop floor"], 'flags' => $flags,
            'recommendations' => $top ? [$this->rec("Look at {$top['work_centre']}: {$top['hours']} h lost in {$top['stops']} stop(s), mostly {$top['main_reason']}.",
                'WORK_CENTRE', $top['centre_id'], $top['work_centre'], $top['url'], 'DOWNTIME_MINUTES', $top['minutes'], ['INVESTIGATION', 'TASK'], ['category' => 'EXCESS_DOWNTIME'])] : [],
            'summary' => $top ? "{$top['work_centre']} caused the most downtime: {$top['hours']} hours over {$top['stops']} stop(s), mostly {$top['main_reason']}."
                : 'No unplanned downtime was recorded.',
        ];
    }

    private function materialsAtRisk(): array
    {
        $components = DB::table('mfg_production_order_components as c')->join('mfg_production_orders as o', 'o.id', '=', 'c.order_id')
            ->whereIn('o.status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])->whereNotNull('c.stock_item_id')
            ->where('c.line_kind', '!=', 'BY_PRODUCT')
            ->get(['c.stock_item_id', 'o.number', 'o.planned_start', 'o.due_date', DB::raw('GREATEST(c.required_qty - c.issued_qty + c.returned_qty, 0) AS outstanding')]);
        $rows = collect();
        $noLead = [];
        foreach ($components->groupBy('stock_item_id') as $itemId => $g) {
            $item = FinStockItem::query()->find($itemId);
            if (! $item) {
                continue;
            }
            $demand = (float) $g->sum('outstanding');
            $onHand = (float) DB::table('fin_stock_positions')->where('item_id', $itemId)->where('status', 'AVAILABLE')->sum('qty');
            $incoming = Schema::hasColumn('purchase_order_items', 'stock_item_id') ? (float) DB::table('purchase_order_items as i')
                ->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')->where('i.stock_item_id', $itemId)->whereNull('p.deleted_at')
                ->whereNotIn('p.status', ['cancelled', 'closed', 'draft', 'rejected'])->sum(DB::raw('GREATEST(i.quantity - COALESCE(i.received_qty, 0), 0)')) : 0.0;
            $param = PlanningParam::query()->where('stock_item_id', $itemId)->first();
            $safety = (float) ($param?->safety_stock ?? 0);
            $projected = $onHand + $incoming - $demand - $safety;
            if ($onHand >= $demand + $safety) {
                continue;
            }
            if ($param?->lead_time_days === null) {
                $noLead[] = $item->code;
            }
            $rows->push(['material' => $item->code, 'on_hand' => round($onHand, 2), 'incoming' => round($incoming, 2), 'needed' => round($demand, 2),
                'safety_stock' => $safety, 'projected' => round($projected, 2), 'runs_out' => $projected < 0 ? 'yes' : 'only if deliveries are late',
                'kind' => 'FORECAST', 'stock_item_id' => $item->id, 'supplier_id' => $param?->preferred_supplier_id,
                'url' => '/manufacturing/planning/mrp', 'records' => $g->pluck('number')->unique()->values()->all()]);
        }
        $rows = $rows->sortBy('projected')->values();
        $flags = $noLead ? [$this->flag('MISSING', 'No lead time is set for '.implode(', ', $noLead).', so when a purchase would arrive cannot be judged.')] : [];

        return [
            'columns' => ['material', 'on_hand', 'incoming', 'needed', 'projected', 'runs_out'], 'rows' => $rows->take(15)->all(),
            'sources' => ["Outstanding material on {$components->pluck('number')->unique()->count()} open production orders, usable stock, and open purchase orders"],
            'flags' => $flags,
            'recommendations' => $rows->filter(fn ($r) => $r['projected'] < 0)->take(5)->map(fn ($r) => $this->rec(
                "Order {$this->num(abs($r['projected']))} more {$r['material']}: needed {$this->num($r['needed'])}, on hand {$this->num($r['on_hand'])}, coming {$this->num($r['incoming'])}.",
                'STOCK_ITEM', $r['stock_item_id'], $r['material'], $r['url'], 'PROJECTED_SHORTFALL', $r['projected'], ['PURCHASE_REQUEST', 'TASK'],
                ['items' => [['code' => $r['material'], 'qty' => abs($r['projected'])]], 'supplier_id' => $r['supplier_id']]))->values()->all(),
            'summary' => $rows->isEmpty() ? 'Every material open orders need is in stock.'
                : "{$rows->where('projected', '<', 0)->count()} material(s) will run out even after open purchase orders arrive; {$rows->count()} depend on deliveries arriving in time.",
        ];
    }

    private function supplierQuality(Carbon $from, Carbon $to): array
    {
        $all = Inspection::query()->whereNotNull('supplier_id')->whereBetween('inspected_at', [$from, $to->copy()->endOfDay()])->get(['id', 'number', 'supplier_id', 'result']);
        $names = DB::table('suppliers')->whereIn('id', $all->pluck('supplier_id')->unique())->pluck('company_name', 'id');
        $rows = $all->groupBy('supplier_id')->map(fn ($g, $id) => [
            'supplier' => $names[$id] ?? "Supplier {$id}", 'inspections' => $g->count(), 'failed' => $g->where('result', Inspection::FAILED)->count(),
            'failure_rate' => round($g->where('result', Inspection::FAILED)->count() / $g->count() * 100, 1), 'kind' => 'ACTUAL', 'supplier_id' => (int) $id,
            'url' => '/manufacturing/quality/inspections', 'records' => $g->where('result', Inspection::FAILED)->pluck('number')->values()->all(),
        ])->sortByDesc('failure_rate')->values();
        $thin = $rows->filter(fn ($r) => $r['inspections'] < 5)->pluck('supplier');
        $flags = [];
        if ($rows->isEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'No incoming inspections recorded a supplier in this period.');
        }
        if ($thin->isNotEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'Fewer than five inspections for '.$thin->implode(', ').'; their rates rest on very few deliveries.');
        }
        $top = $rows->first(fn ($r) => $r['failed'] > 0);

        return [
            'columns' => ['supplier', 'inspections', 'failed', 'failure_rate'], 'rows' => $rows->take(10)->all(),
            'sources' => ["{$all->count()} recorded incoming inspections naming a supplier"], 'flags' => $flags,
            'recommendations' => $top ? [$this->rec("Raise a corrective action with {$top['supplier']}: {$top['failed']} of {$top['inspections']} deliveries failed inspection.",
                'SUPPLIER', $top['supplier_id'], $top['supplier'], $top['url'], 'SUPPLIER_FAILURE_RATE', $top['failure_rate'], ['INVESTIGATION', 'TASK'], ['category' => 'SUPPLIER_QUALITY'])] : [],
            'summary' => $top ? "{$top['supplier']} has the highest failure rate: {$top['failure_rate']}% ({$top['failed']} of {$top['inspections']})." : 'No supplier delivery failed inspection.',
        ];
    }

    private function scrapCauses(Carbon $from, Carbon $to): array
    {
        $orders = PO::query()->whereBetween('completed_at', [$from, $to->copy()->endOfDay()])->with('item.stockItem')->get();
        $made = (float) $orders->sum('qty_completed') + (float) $orders->sum('qty_scrapped');
        $byProduct = $orders->groupBy('item_id')->map(fn ($g) => ['cause' => 'Product '.$g->first()->item?->stockItem?->code, 'scrapped' => (float) $g->sum('qty_scrapped'),
            'reworked' => (float) $g->sum('qty_reworked'), 'kind' => 'ACTUAL', 'item_id' => $g->first()->item_id, 'records' => $g->pluck('number')->all()])
            ->filter(fn ($r) => $r['scrapped'] + $r['reworked'] > 0);
        $failedChecks = DB::table('mfg_inspection_results as r')->join('mfg_inspections as i', 'i.id', '=', 'r.inspection_id')
            ->where('r.passed', false)->whereBetween('i.inspected_at', [$from, $to->copy()->endOfDay()])
            ->groupBy('r.name')->selectRaw('r.name, COUNT(*) AS n')->orderByDesc('n')->limit(5)->get();
        $defects = DB::table('mfg_nonconformances')->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->whereNotNull('defect_code')
            ->groupBy('defect_code')->selectRaw('defect_code, COUNT(*) AS n')->orderByDesc('n')->limit(5)->get();
        $unexplained = DB::table('mfg_production_order_events')->where('action', 'LOSS_RECORDED')->whereNull('reason')->whereBetween('acted_at', [$from, $to->copy()->endOfDay()])->count();

        $rows = $byProduct->sortByDesc(fn ($r) => $r['scrapped'] + $r['reworked'])->values()
            ->concat($failedChecks->map(fn ($c) => ['cause' => "Failed check: {$c->name}", 'scrapped' => null, 'reworked' => null, 'occurrences' => (int) $c->n, 'kind' => 'ACTUAL', 'records' => []]))
            ->concat($defects->map(fn ($d) => ['cause' => "Defect code {$d->defect_code}", 'scrapped' => null, 'reworked' => null, 'occurrences' => (int) $d->n, 'kind' => 'ACTUAL', 'records' => []]));
        $flags = [];
        if ($rows->isEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'No scrap, rework or failed checks were recorded in this period.');
        }
        if ($unexplained) {
            $flags[] = $this->flag('MISSING', "{$unexplained} scrap or rework entries were recorded without a reason, so their cause cannot be told.");
        }
        $top = $byProduct->sortByDesc('scrapped')->first();

        return [
            'columns' => ['cause', 'scrapped', 'reworked', 'occurrences'], 'rows' => $rows->take(15)->all(),
            'sources' => ["{$orders->count()} production orders completed (".$this->num($made).' units made or scrapped), inspection results and non-conformances'],
            'flags' => $flags,
            'recommendations' => $top && $top['scrapped'] > 0 ? [$this->rec("Investigate scrap on {$top['cause']}: {$this->num($top['scrapped'])} scrapped, {$this->num($top['reworked'])} reworked.",
                'PRODUCT', $top['item_id'], $top['cause'], null, 'SCRAP_QTY', $top['scrapped'], ['INVESTIGATION', 'TASK'], ['category' => 'HIGH_SCRAP'])] : [],
            'summary' => $rows->isEmpty() ? 'No scrap or rework was recorded.' : 'The main sources were: '.$rows->take(3)->pluck('cause')->implode('; ').'.',
        ];
    }

    private function decliningMargins(Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $now = $this->profitability->report('product', $from->toDateString(), $to->toDateString());
        $before = $this->profitability->report('product', $from->copy()->subDays($days)->toDateString(), $from->copy()->subDay()->toDateString());
        $prior = collect($before['rows'])->keyBy('key');
        $rows = collect($now['rows'])->filter(fn ($r) => $prior->has($r['key']) && $r['margin_percent'] !== null && $prior[$r['key']]['margin_percent'] !== null)
            ->map(fn ($r) => ['product' => $r['label'], 'margin_before' => $prior[$r['key']]['margin_percent'], 'margin_now' => $r['margin_percent'],
                'change' => round($r['margin_percent'] - $prior[$r['key']]['margin_percent'], 2), 'cost_basis' => $r['cost_status'],
                'kind' => $r['cost_status'] === 'FINAL' ? 'ACTUAL' : 'ESTIMATE', 'records' => []])
            ->filter(fn ($r) => $r['change'] < -1)->sortBy('change')->values();
        $flags = [];
        $newOnly = collect($now['rows'])->reject(fn ($r) => $prior->has($r['key']))->count();
        if ($newOnly) {
            $flags[] = $this->flag('INSUFFICIENT', "{$newOnly} product(s) sold in this period but not the one before, so no trend can be drawn for them.");
        }
        if (collect($now['rows'])->contains(fn ($r) => $r['cost_status'] !== 'FINAL')) {
            $flags[] = $this->flag('MISSING', 'Some costs are provisional (orders not closed yet); those margins are estimates and may move.');
        }
        if (empty($now['rows'])) {
            $flags[] = $this->flag('INSUFFICIENT', 'No sales of manufactured products were invoiced in this period.');
        }
        $top = $rows->first();

        return [
            'columns' => ['product', 'margin_before', 'margin_now', 'change', 'cost_basis'], 'rows' => $rows->take(10)->all(),
            'sources' => ['Sales invoices and production cost for '.$from->toDateString().'–'.$to->toDateString().' against the '.$days.' days before'],
            'flags' => $flags,
            'recommendations' => $top ? [$this->rec("Review price and cost of {$top['product']}: margin fell from {$top['margin_before']}% to {$top['margin_now']}%.",
                'PRODUCT', null, $top['product'], '/manufacturing/costing/profitability', 'MARGIN_PERCENT', $top['margin_now'], ['TASK'], [])] : [],
            'summary' => $top ? "{$rows->count()} product(s) lost margin; the largest fall is {$top['product']}, from {$top['margin_before']}% to {$top['margin_now']}%."
                : 'No product lost more than one point of margin against the previous period.',
        ];
    }

    private function costReduction(Carbon $from, Carbon $to, User $user): array
    {
        $variances = OrderVariance::query()->whereBetween('calculated_at', [$from, $to->copy()->endOfDay()])->where('kind', '!=', 'TOTAL')->whereNotNull('amount')->get()
            ->groupBy('kind')->map(fn ($g, $k) => ['area' => str_replace('_', ' ', strtolower($k)).' variance', 'amount' => round((float) $g->sum('amount'), 2),
                'kind' => 'ACTUAL', 'records' => $g->pluck('order_id')->unique()->count().' orders'])
            ->filter(fn ($r) => $r['amount'] > 0)->sortByDesc('amount')->values();
        $findings = $user->can(R::VIEW_LOSS) ? LossFinding::query()->whereIn('status', LossFinding::OPEN)->orderByDesc('estimated_impact')->limit(5)->get() : collect();
        $rows = $variances->map(fn ($r) => ['area' => $r['area'], 'amount' => $r['amount'], 'kind' => 'ACTUAL', 'records' => [$r['records']]])
            ->concat($findings->map(fn ($f) => ['area' => "{$f->number}: {$f->measure} on {$f->subject_label}", 'amount' => $f->verified_loss ?? $f->estimated_impact,
                'kind' => $f->verified_loss !== null ? 'ACTUAL' : 'ESTIMATE', 'records' => [$f->number], 'url' => "/manufacturing/loss/findings/{$f->uuid}"]));
        $flags = [];
        if ($rows->isEmpty()) {
            $flags[] = $this->flag('INSUFFICIENT', 'There are no cost variances or open loss findings to work from in this period.');
        }
        if (! $user->can(R::VIEW_LOSS)) {
            $flags[] = $this->flag('MISSING', 'Loss prevention findings are not included: your permissions do not cover them.');
        }
        $recs = $findings->take(3)->map(fn ($f) => $this->rec("Act on {$f->number}: {$f->recommended_action}", 'LOSS_FINDING', $f->id, $f->number,
            "/manufacturing/loss/findings/{$f->uuid}", null, null, ['TASK'], []))
            ->concat($variances->take(2)->map(fn ($v) => $this->rec("Reduce {$v['area']}: {$this->money($v['amount'])} over standard in the period.",
                null, null, $v['area'], '/manufacturing/costing/variances', null, null, ['TASK'], [])))->values()->all();
        $realised = (float) LossFinding::query()->whereNotNull('approved_at')->whereNotNull('saving_evidence')->sum('realised_saving');

        return [
            'columns' => ['area', 'amount'], 'rows' => $rows->all(),
            'sources' => ['Production variances closed in the period'.($findings->isNotEmpty() ? ', and open loss prevention findings' : '')],
            'flags' => $flags, 'recommendations' => $recs,
            'summary' => ($variances->isEmpty() ? 'No cost overrun was recorded in the period. ' : "The largest overrun is {$variances->first()['area']}: {$this->money($variances->first()['amount'])}. ")
                .'Amounts on open findings are estimates, not savings; '.($realised > 0 ? "{$this->money($realised)} has been saved and approved with evidence so far." : 'no saving has yet been evidenced and approved.'),
        ];
    }

    // ── Measuring outcomes ───────────────────────────────────────────────────────────────────

    /** The value of a recommendation's metric over a period -- the same calculation as when it was made. */
    public function measure(string $metric, ?string $subjectType, ?int $subjectId, Carbon $from, Carbon $to): ?float
    {
        $end = $to->copy()->endOfDay();

        return match ($metric) {
            'MATERIAL_VARIANCE' => (float) OrderVariance::query()->whereIn('kind', ['MATERIAL_USAGE', 'MATERIAL_PRICE'])->whereBetween('calculated_at', [$from, $end])
                ->whereIn('order_id', PO::query()->where('item_id', $subjectId)->select('id'))->sum('amount'),
            'DOWNTIME_MINUTES' => (float) DowntimeEvent::query()->where('work_centre_id', $subjectId)->whereBetween('started_at', [$from, $end])
                ->whereNotNull('ended_at')->whereHas('reason', fn ($q) => $q->where('is_planned', false))->sum('minutes'),
            'SUPPLIER_FAILURE_RATE' => (function () use ($subjectId, $from, $end) {
                $all = Inspection::query()->where('supplier_id', $subjectId)->whereBetween('inspected_at', [$from, $end])->get(['result']);

                return $all->isEmpty() ? null : round($all->where('result', Inspection::FAILED)->count() / $all->count() * 100, 1);
            })(),
            'SCRAP_QTY' => (float) PO::query()->where('item_id', $subjectId)->whereBetween('completed_at', [$from, $end])->sum('qty_scrapped'),
            'ORDER_LATENESS' => (function () use ($subjectId) {
                $o = PO::query()->find($subjectId);

                return $o?->completed_at ? (float) max(0, $o->due_date->diffInDays($o->completed_at->copy()->startOfDay(), false)) : null;
            })(),
            default => null,
        };
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function rec(string $text, ?string $type, ?int $id, ?string $label, ?string $url, ?string $metric, ?float $baseline, array $actions, array $payload): array
    {
        return ['text' => $text, 'kind' => 'RECOMMENDATION', 'subject_type' => $type, 'subject_id' => $id, 'subject_label' => $label, 'url' => $url,
            'metric' => $metric, 'baseline' => $baseline, 'actions' => $actions, 'payload' => $payload];
    }

    private function flag(string $level, string $message): array
    {
        return ['level' => $level, 'message' => $message];
    }

    private function empty(?string $topic, string $summary, bool $refused = false): array
    {
        return ['topic' => $topic, 'title' => $topic ? self::TOPICS[$topic][0] : 'What I can answer', 'refused' => $refused, 'period' => null,
            'rows' => [], 'columns' => [], 'sources' => [], 'flags' => [], 'recommendations' => [], 'summary' => $summary];
    }

    private function money(float $n): string
    {
        return config('finance.base_currency', 'ZAR').' '.number_format($n, 2);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
