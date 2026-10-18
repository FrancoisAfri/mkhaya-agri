<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\Nonconformance;
use App\Modules\Operations\Manufacturing\Models\NcrAction;
use App\Modules\Operations\Manufacturing\Models\OrderVariance;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The management dashboards (MFG-018): executive, planning, inventory, cost and quality/maintenance.
 *
 * Every figure is read from the records the rest of the module keeps -- nothing is stored here,
 * so a dashboard can never disagree with the screen behind it. All of them take the same filters:
 * a date range, factory, product, production line and order status. None of them carries names of
 * people, so an export holds operational figures only.
 *
 * @phpstan-type Filters array{from: Carbon, to: Carbon, factory_id: ?int, item_id: ?int, line_id: ?int, status: ?string}
 */
class Dashboards
{
    private const ACTIVE = [PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW];

    private const OPEN = [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::ON_HOLD];

    public function __construct(
        private readonly ProductionOrderLifecycle $lifecycle,
        private readonly Scheduler $scheduler,
        private readonly DemandPlanning $planning,
        private readonly Profitability $profitability,
        private readonly Oee $oee,
        private readonly Maintenance $maintenance,
    ) {}

    /** @param  Filters  $f */
    public function executive(array $f): array
    {
        $received = $this->receipts($f);
        $variances = $this->variances($f);
        $margin = $this->profitability->report('product', $f['from']->toDateString(), $f['to']->toDateString(), $f['factory_id']);
        $waste = (float) DB::table('fin_stock_movements')->where('type', 'scrap')->whereBetween('movement_date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->when($f['item_id'], fn ($q) => $q->where('item_id', $this->stockItemOf($f['item_id'])))->sum(DB::raw('ABS(value)'));
        $downtime = $this->downtime($f)->reject(fn ($e) => $e->reason?->is_planned);
        $realised = LossFinding::query()->whereNotNull('approved_at')->whereNotNull('saving_evidence')
            ->whereBetween('approved_at', [$f['from'], $f['to']])->when($f['factory_id'], fn ($q, $id) => $q->where('factory_id', $id))->sum('realised_saving');

        return [
            'kpis' => [
                ['key' => 'output_qty', 'value' => round((float) $received->sum('qty'), 2)],
                ['key' => 'output_value', 'value' => round((float) $received->sum('value'), 2), 'money' => true],
                ['key' => 'orders_completed', 'value' => $this->orders($f)->whereBetween('completed_at', [$f['from'], $f['to']])->count()],
                ['key' => 'cost_variance', 'value' => round((float) $variances->where('kind', 'TOTAL')->sum('amount'), 2), 'money' => true, 'bad_when' => 'positive'],
                ['key' => 'margin', 'value' => $margin['totals']['margin'], 'money' => true, 'extra' => $margin['totals']['margin_percent']],
                ['key' => 'material_waste', 'value' => round($waste, 2), 'money' => true],
                ['key' => 'overdue_orders', 'value' => $this->orders($f, false)->whereIn('status', self::OPEN)->whereDate('due_date', '<', today())->count(), 'bad_when' => 'positive'],
                ['key' => 'unplanned_downtime_hours', 'value' => round((float) $downtime->sum('minutes') / 60, 1)],
                ['key' => 'realised_savings', 'value' => round((float) $realised, 2), 'money' => true],
            ],
            'tables' => [
                'output_by_product' => $received->groupBy('code')->map(fn ($g, $code) => ['product' => $code, 'qty' => round((float) $g->sum('qty'), 2),
                    'value' => round((float) $g->sum('value'), 2)])->sortByDesc('value')->values()->take(15)->all(),
                'variance_by_kind' => $variances->where('kind', '!=', 'TOTAL')->groupBy('kind')
                    ->map(fn ($g, $k) => ['kind' => $k, 'amount' => round((float) $g->sum('amount'), 2)])->values()->all(),
                // MFG-024: the factories side by side, for somebody who may see all of them.
                'by_factory' => ($f['consolidated'] ?? false) && ! $f['factory_id'] ? $this->byFactory($f) : [],
            ],
            'currency' => $margin['currency'],
        ];
    }

    /** @param  Filters  $f */
    public function planning(array $f): array
    {
        $adherence = $this->scheduler->adherence($f['from'], $f['to']);
        $centres = $this->centreIds($f);
        $loading = collect($this->planning->capacity(4))->filter(fn ($c) => $centres === null || in_array($c['id'], $centres, true))->values();
        $late = $this->orders($f, false)->whereIn('status', self::OPEN)
            ->where(fn ($q) => $q->whereDate('due_date', '<', today())->orWhereColumn('sched_finish', '>', 'due_date'))
            ->with('item.stockItem:id,code')->orderBy('due_date')->limit(50)->get();

        return [
            'kpis' => [
                ['key' => 'schedule_adherence', 'value' => $adherence['percent'], 'suffix' => '%'],
                ['key' => 'average_slip_days', 'value' => $adherence['average_slip_days']],
                ['key' => 'late_orders', 'value' => $late->count(), 'bad_when' => 'positive'],
                ['key' => 'orders_short', 'value' => count(array_unique(array_column($this->shortages($f), 'order'))), 'bad_when' => 'positive'],
                ['key' => 'bottlenecks', 'value' => $loading->where('bottleneck', true)->count(), 'bad_when' => 'positive'],
            ],
            'tables' => [
                'loading' => $loading->map(fn ($c) => ['work_centre' => $c['code']] + collect($c['weeks'])->mapWithKeys(fn ($w) => [$w['week'] => $w['percent']])->all())->all(),
                'bottlenecks' => $loading->where('bottleneck', true)->map(fn ($c) => ['work_centre' => $c['code'],
                    'worst_week' => collect($c['weeks'])->sortByDesc('percent')->first()['week'], 'load_percent' => collect($c['weeks'])->max('percent')])->values()->all(),
                'shortages' => $this->shortages($f),
                'late_orders' => $late->map(fn (PO $o) => ['order' => $o->number, 'product' => $o->item?->stockItem?->code, 'status' => $o->status,
                    'due' => $o->due_date?->toDateString(), 'scheduled_finish' => $o->sched_finish?->toDateString()])->all(),
            ],
        ];
    }

    /** @param  Filters  $f */
    public function inventory(array $f): array
    {
        $itemFilter = $f['item_id'] ? $this->stockItemOf($f['item_id']) : null;
        $warehouses = $f['factory_id'] ? DB::table('mfg_production_orders')->where('factory_id', $f['factory_id'])->distinct()->pluck('warehouse_id')->all() : null;
        $positions = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->when($itemFilter, fn ($q, $id) => $q->where('p.item_id', $id))->when($warehouses, fn ($q, $w) => $q->whereIn('p.warehouse_id', $w))
            ->where('p.qty', '!=', 0)->groupBy('p.status')->selectRaw('p.status, SUM(p.qty) AS qty, SUM(p.qty * COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost, 0)) AS value')->get()->keyBy('status');
        $reservations = FinStockReservation::query()->where('status', FinStockReservation::OPEN)
            ->when($itemFilter, fn ($q, $id) => $q->where('item_id', $id))->when($warehouses, fn ($q, $w) => $q->whereIn('warehouse_id', $w))
            ->get()->groupBy('source_type')->map(fn ($g, $s) => ['allocated_to' => $s, 'qty' => round((float) $g->sum(fn ($r) => $r->remaining()), 2), 'reservations' => $g->count()])->values();
        $wip = (float) DB::table('fin_stock_movements as m')->join('mfg_production_orders as o', fn ($j) => $j->on('o.id', '=', 'm.source_id')->where('m.source_type', PO::SOURCE_TYPE))
            ->whereIn('o.status', self::ACTIVE)->when($f['factory_id'], fn ($q, $id) => $q->where('o.factory_id', $id))->when($f['item_id'], fn ($q, $id) => $q->where('o.item_id', $id))
            ->selectRaw("COALESCE(SUM(CASE WHEN m.type = 'mfg_issue' THEN ABS(m.value) WHEN m.type IN ('mfg_return', 'mfg_receipt') THEN -ABS(m.value) ELSE 0 END), 0) AS wip")->value('wip');
        $expiring = DB::table('fin_stock_positions as p')->join('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->where('p.qty', '>', 0)->whereNotNull('l.expires_on')->where('l.expires_on', '<=', today()->addDays(30)->toDateString())
            ->when($itemFilter, fn ($q, $id) => $q->where('p.item_id', $id))->when($warehouses, fn ($q, $w) => $q->whereIn('p.warehouse_id', $w))
            ->orderBy('l.expires_on')->limit(50)->get(['i.code', 'l.lot_number', 'l.expires_on', 'p.qty', 'p.status']);
        $cutoff = today()->subDays(90)->toDateString();
        $slow = DB::table('fin_stock_items as i')->where('i.qty_on_hand', '>', 0)->where('i.is_active', true)
            ->when($itemFilter, fn ($q, $id) => $q->where('i.id', $id))
            ->whereNotExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.item_id', 'i.id')->where('m.qty', '<', 0)->where('m.movement_date', '>=', $cutoff))
            ->orderByDesc(DB::raw('i.qty_on_hand * COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost, 0)'))->limit(30)
            ->get(['i.code', 'i.name', 'i.qty_on_hand', DB::raw('i.qty_on_hand * COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost, 0) AS value')]);
        $counted = DB::table('fin_stock_movements')->where('source_type', 'CYCLE_COUNT')->whereBetween('movement_date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->when($itemFilter, fn ($q, $id) => $q->where('item_id', $id))->selectRaw('COUNT(*) AS n, COALESCE(SUM(ABS(value)), 0) AS v')->first();
        $stockValue = (float) $positions->sum('value');

        return [
            'kpis' => [
                ['key' => 'available_value', 'value' => round((float) ($positions['AVAILABLE']->value ?? 0), 2), 'money' => true],
                ['key' => 'held_value', 'value' => round((float) $positions->except('AVAILABLE')->sum('value'), 2), 'money' => true],
                ['key' => 'allocated_qty', 'value' => round((float) $reservations->sum('qty'), 2)],
                ['key' => 'wip_value', 'value' => round($wip, 2), 'money' => true],
                ['key' => 'expiring_lots', 'value' => $expiring->count(), 'bad_when' => 'positive'],
                ['key' => 'orders_short', 'value' => count(array_unique(array_column($this->shortages($f), 'order'))), 'bad_when' => 'positive'],
                ['key' => 'count_adjustment_percent', 'value' => $stockValue > 0 ? round((float) $counted->v / $stockValue * 100, 2) : null, 'suffix' => '%', 'bad_when' => 'positive'],
            ],
            'tables' => [
                'by_status' => $positions->map(fn ($p, $s) => ['status' => $s, 'qty' => round((float) $p->qty, 2), 'value' => round((float) $p->value, 2)])->values()->all(),
                'allocations' => $reservations->all(),
                'expiry_risk' => $expiring->map(fn ($e) => ['item' => $e->code, 'lot' => $e->lot_number, 'expires' => $e->expires_on, 'qty' => (float) $e->qty, 'status' => $e->status])->all(),
                'shortages' => $this->shortages($f),
                'slow_moving' => $slow->map(fn ($s) => ['item' => $s->code, 'name' => $s->name, 'qty' => (float) $s->qty_on_hand, 'value' => round((float) $s->value, 2)])->all(),
            ],
            'notes' => ['stock_accuracy' => "{$counted->n} count adjustments in the period, as a share of stock value. Counts that agreed post nothing and are not seen here."],
        ];
    }

    /** @param  Filters  $f */
    public function costs(array $f): array
    {
        $variances = $this->variances($f);
        $totals = $variances->where('kind', 'TOTAL');
        $standard = (float) $totals->sum(fn ($v) => (float) ($v->inputs['standard_for_output'] ?? 0));
        $actual = (float) $totals->sum(fn ($v) => (float) ($v->inputs['actual'] ?? 0));
        $orders = $this->orders($f)->whereBetween('completed_at', [$f['from'], $f['to']])->with('operations')->get();
        $plannedLabour = (float) $orders->sum(fn (PO $o) => $o->operations->sum('planned_labour_minutes'));
        $actualLabour = (float) $orders->sum(fn (PO $o) => $o->operations->sum('actual_labour_minutes'));
        $margins = $this->profitability->report('product', $f['from']->toDateString(), $f['to']->toDateString(), $f['factory_id']);

        return [
            'kpis' => [
                ['key' => 'standard_cost', 'value' => round($standard, 2), 'money' => true],
                ['key' => 'actual_cost', 'value' => round($actual, 2), 'money' => true],
                ['key' => 'cost_variance', 'value' => round($actual - $standard, 2), 'money' => true, 'bad_when' => 'positive'],
                ['key' => 'material_usage_variance', 'value' => round((float) $variances->where('kind', 'MATERIAL_USAGE')->sum('amount'), 2), 'money' => true, 'bad_when' => 'positive'],
                ['key' => 'labour_efficiency', 'value' => $actualLabour > 0 ? round($plannedLabour / $actualLabour * 100, 1) : null, 'suffix' => '%'],
                ['key' => 'scrap_cost', 'value' => round((float) $orders->sum('act_scrap_cost'), 2), 'money' => true],
                ['key' => 'margin_percent', 'value' => $margins['totals']['margin_percent'], 'suffix' => '%'],
            ],
            'tables' => [
                'variance_by_kind' => $variances->where('kind', '!=', 'TOTAL')->groupBy('kind')
                    ->map(fn ($g, $k) => ['kind' => $k, 'amount' => round((float) $g->sum('amount'), 2)])->values()->all(),
                'orders_over_standard' => $totals->sortByDesc('amount')->take(15)->map(fn ($v) => ['order' => $v->order?->number,
                    'standard' => round((float) ($v->inputs['standard_for_output'] ?? 0), 2), 'actual' => round((float) ($v->inputs['actual'] ?? 0), 2),
                    'variance' => round((float) $v->amount, 2)])->values()->all(),
                'product_margins' => collect($margins['rows'])->take(20)->map(fn ($r) => array_intersect_key($r, array_flip(['label', 'qty', 'revenue', 'cost', 'margin', 'margin_percent', 'cost_status'])))->values()->all(),
            ],
            'currency' => $margins['currency'],
        ];
    }

    /**
     * @param  Filters  $f
     * @param  array{quality: bool, maintenance: bool}  $sections  what this user may see
     */
    public function qualityMaintenance(array $f, array $sections): array
    {
        $kpis = [];
        $tables = [];

        if ($sections['quality']) {
            $inspections = Inspection::query()->whereBetween('inspected_at', [$f['from'], $f['to']])
                ->when($f['item_id'], fn ($q) => $q->where('stock_item_id', $this->stockItemOf($f['item_id'])))
                ->when($f['factory_id'], fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('production_order_id')
                    ->orWhereIn('production_order_id', PO::query()->where('factory_id', $id)->select('id'))))->get(['result', 'inspected_at', 'stage']);
            $ncrs = Nonconformance::query()->whereNotIn('status', [Nonconformance::CLOSED, Nonconformance::VOID])->get(['severity', 'status', 'due_date']);
            $actions = NcrAction::query()->whereNull('completed_at')->get(['type', 'due_date']);
            $failed = $inspections->where('result', Inspection::FAILED)->count();
            $kpis = [...$kpis,
                ['key' => 'first_pass_yield', 'value' => $inspections->count() ? round(($inspections->count() - $failed) / $inspections->count() * 100, 1) : null, 'suffix' => '%'],
                ['key' => 'open_ncrs', 'value' => $ncrs->count(), 'bad_when' => 'positive'],
                ['key' => 'open_corrective_actions', 'value' => $actions->count()],
                ['key' => 'overdue_corrective_actions', 'value' => $actions->filter(fn ($a) => $a->due_date && Carbon::parse($a->due_date)->lt(today()))->count(), 'bad_when' => 'positive'],
            ];
            $tables['defect_trend'] = $inspections->groupBy(fn ($i) => $i->inspected_at->copy()->startOfWeek()->toDateString())->sortKeys()
                ->map(fn ($g, $w) => ['week' => $w, 'inspections' => $g->count(), 'failed' => $g->where('result', Inspection::FAILED)->count(),
                    'failure_percent' => round($g->where('result', Inspection::FAILED)->count() / $g->count() * 100, 1)])->values()->all();
            $tables['ncrs_by_severity'] = $ncrs->groupBy('severity')->map(fn ($g, $s) => ['severity' => $s, 'open' => $g->count(),
                'overdue' => $g->filter(fn ($n) => $n->due_date && Carbon::parse($n->due_date)->lt(today()))->count()])->values()->all();
        }

        if ($sections['maintenance']) {
            $overdue = $this->maintenance->overdue();
            $centres = WorkCentre::query()->where('is_active', true)->when($f['factory_id'], fn ($q, $id) => $q->where('factory_id', $id))
                ->when($f['line_id'], fn ($q, $id) => $q->where('production_line_id', $id))->orderBy('code')->get();
            $availability = $centres->map(function (WorkCentre $c) use ($f) {
                $o = $this->oee->forCentre($c, $f['from'], $f['to']);

                return ['work_centre' => $c->code, 'availability' => $o['availability'], 'oee' => $o['oee'],
                    'unplanned_downtime_minutes' => $o['inputs']['unplanned_downtime_minutes']];
            });
            $kpis = [...$kpis,
                ['key' => 'overdue_maintenance', 'value' => $overdue->count(), 'bad_when' => 'positive'],
                ['key' => 'equipment_availability', 'value' => $availability->whereNotNull('availability')->avg('availability') !== null
                    ? round($availability->whereNotNull('availability')->avg('availability'), 1) : null, 'suffix' => '%'],
                ['key' => 'breakdowns', 'value' => $this->downtime($f)->filter(fn ($e) => $e->reason?->category === 'BREAKDOWN')->count()],
            ];
            $tables['availability'] = $availability->values()->all();
            $tables['overdue_maintenance'] = $overdue->map(fn ($w) => ['work_order' => $w->work_order_number ?? $w->id, 'machine' => $w->asset?->name,
                'scheduled_for' => $w->scheduled_for ? Carbon::parse($w->scheduled_for)->toDateString() : null, 'status' => $w->status])->values()->all();
        }

        return ['kpis' => $kpis, 'tables' => $tables];
    }

    /** Output, overdue orders and lost time per factory, for the consolidated view. */
    private function byFactory(array $f): array
    {
        return \App\Modules\Operations\Manufacturing\Models\Factory::query()->where('is_active', true)->orderBy('code')->get()->map(function ($factory) use ($f) {
            $one = ['factory_id' => $factory->id] + $f;

            return ['factory' => $factory->code, 'output_qty' => round((float) $this->receipts($one)->sum('qty'), 2),
                'orders_completed' => $this->orders($one)->whereBetween('completed_at', [$f['from'], $f['to']])->count(),
                'overdue_orders' => $this->orders($one, false)->whereIn('status', self::OPEN)->whereDate('due_date', '<', today())->count(),
                'unplanned_downtime_hours' => round((float) $this->downtime($one)->reject(fn ($e) => $e->reason?->is_planned)->sum('minutes') / 60, 1)];
        })->values()->all();
    }

    /** Rows for a CSV: every KPI, then every table, flattened. */
    public function flatten(array $dashboard): array
    {
        $rows = [['section', 'name', 'value']];
        foreach ($dashboard['kpis'] as $k) {
            $rows[] = ['kpi', $k['key'], $k['value']];
        }
        foreach ($dashboard['tables'] as $name => $table) {
            if ($table === []) {
                continue;
            }
            $rows[] = [];
            $rows[] = [$name, ...array_keys((array) $table[0])];
            foreach ($table as $r) {
                $rows[] = [$name, ...array_values((array) $r)];
            }
        }

        return $rows;
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /** Orders under the filters; $statusFilter false ignores the status filter (for "overdue" and "late"). */
    private function orders(array $f, bool $statusFilter = true): Builder
    {
        return PO::query()
            ->when($f['factory_id'], fn ($q, $id) => $q->where('factory_id', $id))
            ->when($f['item_id'], fn ($q, $id) => $q->where('item_id', $id))
            ->when($statusFilter && $f['status'], fn ($q) => $q->where('status', $f['status']))
            ->when($f['line_id'], fn ($q, $id) => $q->whereHas('operations', fn ($o) => $o->whereIn('work_centre_id',
                WorkCentre::query()->where('production_line_id', $id)->select('id'))));
    }

    private function receipts(array $f): Collection
    {
        return DB::table('fin_stock_movements as m')->join('mfg_production_orders as o', fn ($j) => $j->on('o.id', '=', 'm.source_id')->where('m.source_type', PO::SOURCE_TYPE))
            ->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')->where('m.type', 'mfg_receipt')->whereNull('m.reversal_of_id')
            ->whereBetween('m.movement_date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->whereIn('o.id', $this->orders($f)->select('id'))
            ->get(['i.code', DB::raw('ABS(m.qty) AS qty'), DB::raw('ABS(m.value) AS value')]);
    }

    private function variances(array $f): Collection
    {
        return OrderVariance::query()->whereBetween('calculated_at', [$f['from'], $f['to']])
            ->whereIn('order_id', $this->orders($f)->select('id'))->with('order:id,number')->get();
    }

    private function downtime(array $f): Collection
    {
        return DowntimeEvent::query()->whereBetween('started_at', [$f['from'], $f['to']])
            ->when($f['factory_id'], fn ($q, $id) => $q->where('factory_id', $id))
            ->when($this->centreIds($f) !== null, fn ($q) => $q->whereIn('work_centre_id', $this->centreIds($f)))
            ->with('reason')->get();
    }

    /** @return list<int>|null work centres on the filtered line or factory; null when unfiltered */
    private function centreIds(array $f): ?array
    {
        if (! $f['line_id'] && ! $f['factory_id']) {
            return null;
        }

        return WorkCentre::query()->when($f['factory_id'], fn ($q, $id) => $q->where('factory_id', $id))
            ->when($f['line_id'], fn ($q, $id) => $q->where('production_line_id', $id))->pluck('id')->all();
    }

    private array $shortageCache = [];

    private function shortages(array $f): array
    {
        $key = md5(serialize([$f['factory_id'], $f['item_id'], $f['line_id'], $f['status']]));

        return $this->shortageCache[$key] ??= $this->orders($f)->whereIn('status', [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
            ->orderBy('due_date')->limit(200)->get()
            ->flatMap(fn (PO $o) => collect($this->lifecycle->shortages($o))->map(fn ($s) => ['order' => $o->number, 'due' => $o->due_date?->toDateString()] + $s))
            ->values()->all();
    }

    private function stockItemOf(int $itemId): ?int
    {
        return DB::table('mfg_items')->where('id', $itemId)->value('stock_item_id');
    }
}
