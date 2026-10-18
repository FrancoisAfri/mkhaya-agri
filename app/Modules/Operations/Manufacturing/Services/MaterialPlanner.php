<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Operations\Manufacturing\Models\Forecast;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanItem;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\PlanRecommendation;
use App\Modules\Operations\Manufacturing\Models\PlanRun;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Material requirements planning (MFG-005), in weekly buckets over a horizon.
 *
 * Independent demand is confirmed sales order lines (outstanding quantity), approved forecasts
 * where confirmed orders do not already cover them, and stock reserved for anything other than
 * production. Dependent demand is the open components of existing production orders, plus the
 * components of every order this run plans -- exploded level by level through the BOM in force on
 * the release date, so a sub-assembly is planned before its own materials.
 *
 * Supply is usable stock (available status, not expired, and not expiring before it is needed),
 * open purchase order lines, and the remaining output of open production orders. Stock reserved
 * for a production order is NOT subtracted from supply, because that order's open components are
 * already counted as demand: subtracting both would count the same stock twice.
 *
 * Net requirements below safety stock become planned orders, rounded up to the minimum order
 * quantity and order multiple, released a lead time before they are needed. Everything is a
 * recommendation; nothing is ordered here.
 */
class MaterialPlanner
{
    private const MAX_LEVELS = 32;

    private Carbon $start;

    private int $weeks;

    /** @var array<int, array> per stock item: params resolved once */
    private array $params = [];

    /** @var array<int, array> per stock item: buckets and pegging under construction */
    private array $plan = [];

    private array $runExceptions = [];

    public function __construct(
        private readonly BomResolver $boms,
        private readonly BomExploder $exploder,
        private readonly NumberSequences $numbers,
    ) {}

    public function run(?User $actor, int $horizonWeeks = 12, ?int $companyId = null): PlanRun
    {
        $this->start = now()->startOfWeek();
        $this->weeks = max(1, min(52, $horizonWeeks));
        $this->params = $this->plan = $this->runExceptions = [];
        $companyId ??= app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $actor?->company_id;

        $this->independentDemand();
        $this->existingOrderDemand();
        $levels = $this->lowLevelCodes();

        $recommendations = [];
        for ($level = 0; $level <= max($levels ?: [0]); $level++) {
            foreach (array_keys(array_filter($levels, fn ($l) => $l === $level)) as $itemId) {
                foreach ($this->net($itemId) as $rec) {
                    $recommendations[] = $rec;
                }
            }
        }

        return DB::transaction(function () use ($actor, $companyId, $levels, $recommendations) {
            PlanRun::query()->where('status', 'OPEN')->update(['status' => 'SUPERSEDED']);

            $run = PlanRun::create([
                'company_id' => $companyId, 'number' => $this->numbers->next($companyId, 'PLAN_RUN'),
                'starts_on' => $this->start->toDateString(), 'horizon_weeks' => $this->weeks, 'status' => 'OPEN',
                'run_by' => $actor?->id, 'exceptions' => array_values(array_unique($this->runExceptions)) ?: null,
            ]);

            foreach ($levels as $itemId => $level) {
                $p = $this->plan[$itemId] ?? null;
                if (! $p) {
                    continue;
                }
                PlanItem::create([
                    'company_id' => $companyId, 'run_id' => $run->id, 'stock_item_id' => $itemId, 'level' => $level,
                    'make_or_buy' => $this->param($itemId)['make_or_buy'], 'on_hand' => $p['on_hand'], 'safety_stock' => $this->param($itemId)['safety'],
                    'gross' => array_sum($p['gross']), 'scheduled_receipts' => array_sum($p['receipts']), 'net' => array_sum($p['net']),
                    'planned' => array_sum($p['planned']), 'projected_end' => end($p['projected']) ?: 0,
                    'shortage_on' => $p['shortage_on'], 'available_on' => $p['available_on'],
                    'timeline' => $this->timeline($p), 'demand' => $p['pegging'], 'exceptions' => array_values(array_unique($p['exceptions'])) ?: null,
                ]);
            }

            foreach ($recommendations as $rec) {
                PlanRecommendation::create($rec + ['company_id' => $companyId, 'run_id' => $run->id, 'status' => PlanRecommendation::PROPOSED]);
            }

            $run->update(['summary' => [
                'items' => count(array_filter($this->plan)),
                'purchase' => count(array_filter($recommendations, fn ($r) => $r['kind'] === 'PURCHASE')),
                'produce' => count(array_filter($recommendations, fn ($r) => $r['kind'] === 'PRODUCE')),
                'short_items' => count(array_filter($this->plan, fn ($p) => $p && $p['shortage_on'])),
                'exceptions' => collect($this->plan)->filter()->sum(fn ($p) => count(array_unique($p['exceptions']))),
            ]]);

            return $run->refresh();
        });
    }

    // ── Demand ───────────────────────────────────────────────────────────────────────────────

    private function independentDemand(): void
    {
        // Confirmed sales order lines, matched to stock items by code as stock relief does.
        $sales = DB::table('fin_sales_order_lines as l')
            ->join('fin_sales_orders as o', 'o.id', '=', 'l.order_id')
            ->join('fin_items as fi', 'fi.id', '=', 'l.item_id')
            ->join('fin_stock_items as s', fn ($j) => $j->on('s.code', '=', 'fi.code')->on('s.company_id', '=', 'o.company_id'))
            ->whereIn('o.status', ['open', 'part_delivered'])
            ->whereRaw('l.quantity - l.qty_delivered > 0')
            ->get(['s.id as stock_item_id', 'o.number', 'o.order_date', DB::raw('l.quantity - l.qty_delivered as qty')]);

        if ($sales->isNotEmpty()) {
            $this->runExceptions[] = 'Sales orders carry no requested delivery date; their order date is used as the date needed.';
        }

        $ordered = [];   // item => month => qty, for forecast consumption
        foreach ($sales as $s) {
            $this->addGross((int) $s->stock_item_id, Carbon::parse($s->order_date), (float) $s->qty, 'SALES_ORDER', $s->number);
            $month = Carbon::parse($s->order_date)->format('Y-m');
            $ordered[$s->stock_item_id][$month] = ($ordered[$s->stock_item_id][$month] ?? 0) + (float) $s->qty;
        }

        // Approved forecasts, consumed by confirmed orders in the same month and spread over its weeks.
        $end = $this->start->copy()->addWeeks($this->weeks);
        foreach (Forecast::query()->where('status', 'APPROVED')->where('period_start', '<', $end)
            ->where('period_start', '>=', $this->start->copy()->startOfMonth())->get() as $f) {
            $month = $f->period_start->format('Y-m');
            $remaining = (float) $f->qty - ($ordered[$f->stock_item_id][$month] ?? 0);
            if ($remaining <= 0) {
                continue;
            }
            $weeks = collect(range(0, 5))->map(fn ($w) => $f->period_start->copy()->startOfMonth()->addWeeks($w))
                ->filter(fn ($d) => $d->month === $f->period_start->month)->values();
            foreach ($weeks as $week) {
                $this->addGross((int) $f->stock_item_id, $week, $remaining / $weeks->count(), 'FORECAST', $f->period_start->format('M Y'));
            }
        }

        // Stock reserved for anything other than production is spoken for.
        foreach (FinStockReservation::query()->where('status', FinStockReservation::OPEN)->where('source_type', '!=', PO::SOURCE_TYPE)
            ->selectRaw('item_id, source_type, SUM(qty - qty_consumed) AS qty')->groupBy('item_id', 'source_type')->get() as $r) {
            if ((float) $r->qty > 0) {
                $this->addGross((int) $r->item_id, $this->start, (float) $r->qty, 'RESERVATION', $r->source_type);
            }
        }
    }

    /** Components still to be issued to open production orders, and their remaining output as supply. */
    private function existingOrderDemand(): void
    {
        $open = [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::ON_HOLD];

        foreach (PO::query()->whereIn('status', $open)->with(['components', 'item'])->get() as $order) {
            $when = Carbon::parse($order->planned_start ?? now());
            foreach ($order->components as $c) {
                if ($c->isConsumed() && $c->outstanding() > 0) {
                    $this->addGross((int) $c->stock_item_id, $when, $c->outstanding(), 'PRODUCTION_ORDER', $order->number);
                }
            }
            $remaining = (float) $order->planned_qty - (float) $order->qty_completed;
            if ($remaining > 0 && $order->item) {
                $this->addReceipt((int) $order->item->stock_item_id, Carbon::parse($order->due_date), $remaining);
            }
        }

        // Open purchase order lines, where the line names its material.
        if (Schema::hasColumn('purchase_order_items', 'stock_item_id')) {
            foreach (DB::table('purchase_order_items as i')->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')
                ->whereNotNull('i.stock_item_id')->whereNull('p.deleted_at')
                ->whereNotIn('p.status', ['draft', 'cancelled', 'closed', 'rejected', 'received', 'completed'])
                ->whereRaw('i.quantity - COALESCE(i.received_qty, 0) > 0')
                ->get(['i.stock_item_id', 'p.expected_delivery', 'p.order_date', DB::raw('i.quantity - COALESCE(i.received_qty, 0) as qty')]) as $line) {
                $this->addReceipt((int) $line->stock_item_id, Carbon::parse($line->expected_delivery ?? $line->order_date ?? now()), (float) $line->qty);
            }
        }
    }

    // ── Levels ───────────────────────────────────────────────────────────────────────────────

    /**
     * Low-level codes: an item is planned after everything that uses it, so the demand its parents'
     * planned orders put on it is known first.
     *
     * @return array<int, int>
     */
    private function lowLevelCodes(): array
    {
        $levels = array_fill_keys(array_keys($this->plan), 0);
        $queue = array_keys($levels);

        while ($queue) {
            $itemId = array_shift($queue);
            if ($levels[$itemId] >= self::MAX_LEVELS) {
                continue;
            }
            foreach ($this->components($itemId) as $componentId) {
                $next = $levels[$itemId] + 1;
                if (! isset($levels[$componentId]) || $levels[$componentId] < $next) {
                    $levels[$componentId] = $next;
                    $queue[] = $componentId;
                }
            }
        }

        foreach (array_keys($levels) as $itemId) {
            $this->ensure($itemId);
        }

        return $levels;
    }

    /** @return list<int> stock item ids the item's applicable BOM consumes */
    private function components(int $stockItemId): array
    {
        if ($this->param($stockItemId)['make_or_buy'] !== 'MAKE') {
            return [];
        }
        $item = Item::query()->where('stock_item_id', $stockItemId)->first();
        $bom = $item ? $this->boms->applicable($item->id, now()) : null;
        if (! $bom) {
            return [];
        }

        return collect($this->exploder->explode($bom->load(['lines.uom', 'lines.component', 'yieldUom']), 1, false, now())['requirements'])
            ->pluck('stock_item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    // ── Netting ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array> recommendations for one item */
    private function net(int $itemId): array
    {
        $this->ensure($itemId);
        $p = &$this->plan[$itemId];
        $param = $this->param($itemId);
        $recs = [];

        if ($param['make_or_buy'] === 'MAKE' && ! $param['bom']) {
            $p['exceptions'][] = 'Made here, but there is no approved BOM to plan its materials.';
        }
        if ($param['lead'] === null) {
            $p['exceptions'][] = 'No lead time: planned as if it arrives the day it is ordered.';
        }

        $projected = $p['on_hand'];
        $projectedWithoutPlan = $p['on_hand'];
        $safety = $param['safety'];

        for ($b = 0; $b < $this->weeks; $b++) {
            $projected += $p['receipts'][$b] - $p['gross'][$b] - $p['expiring'][$b];
            $projectedWithoutPlan += $p['receipts'][$b] - $p['gross'][$b] - $p['expiring'][$b];

            if ($projectedWithoutPlan < 0 && $p['shortage_on'] === null) {
                $p['shortage_on'] = $this->bucketDate($b)->toDateString();
            }

            if ($projected < $safety - 0.00005) {
                $net = $safety - $projected;
                $qty = $this->lotSize($net, $param);
                $need = $this->bucketDate($b);
                $release = $need->copy()->subDays($param['lead'] ?? 0);

                $p['net'][$b] += $net;
                $p['planned'][$b] += $qty;
                $projected += $qty;

                if ($release->lt(now()->startOfDay())) {
                    $p['exceptions'][] = sprintf('Needed by %s but its lead time means it should have been released on %s.', $need->toDateString(), $release->toDateString());
                    $p['available_on'] = now()->addDays($param['lead'] ?? 0)->toDateString();
                    $release = now()->startOfDay();
                }

                $recs[] = [
                    'stock_item_id' => $itemId, 'kind' => $param['make_or_buy'] === 'MAKE' ? 'PRODUCE' : 'PURCHASE',
                    'qty' => round($qty, 4), 'net_qty' => round($net, 4), 'need_by' => $need->toDateString(), 'release_by' => $release->toDateString(),
                    'supplier_id' => $param['make_or_buy'] === 'BUY' ? $param['supplier_id'] : null,
                    'unit_price' => $param['make_or_buy'] === 'BUY' ? $param['last_price'] : null,
                ];

                // A planned make order puts demand on its materials when it is released.
                if ($param['make_or_buy'] === 'MAKE' && $param['bom']) {
                    $bom = $this->boms->applicable($param['item_id'], $release) ?? $param['bom'];
                    foreach ($this->exploder->explode($bom->load(['lines.uom', 'lines.component', 'yieldUom']), $qty, true, $release)['requirements'] as $r) {
                        $this->addGross((int) $r['stock_item_id'], $release, (float) $r['quantity'], 'PLANNED_ORDER', $param['code'].' '.$need->toDateString());
                    }
                }
            }

            $p['projected'][$b] = round($projected, 4);
        }

        if ($param['max'] !== null && end($p['projected']) > $param['max'] + 0.0001) {
            $p['exceptions'][] = sprintf('Projected to end the horizon at %s, above its maximum stock of %s.', $this->num(end($p['projected'])), $this->num($param['max']));
        }
        if ($param['make_or_buy'] === 'BUY' && ! $param['supplier_id'] && $recs) {
            $p['exceptions'][] = 'No preferred or previous supplier to buy it from.';
        }
        if ($p['past_due'] > 0) {
            $p['exceptions'][] = sprintf('%s of its demand is already past due.', $this->num($p['past_due']));
        }

        return $recs;
    }

    private function lotSize(float $net, array $param): float
    {
        $qty = max($net, (float) ($param['moq'] ?? 0));
        if ($param['multiple'] && (float) $param['multiple'] > 0) {
            $qty = ceil(round($qty / (float) $param['multiple'], 6)) * (float) $param['multiple'];
        }

        return round($qty, 4);
    }

    // ── Buckets ──────────────────────────────────────────────────────────────────────────────

    private function addGross(int $itemId, Carbon $date, float $qty, string $type, ?string $ref): void
    {
        $b = $this->bucket($date);
        if ($b === null) {
            return;
        }
        $this->ensure($itemId);
        if ($date->lt($this->start)) {
            $this->plan[$itemId]['past_due'] += $qty;
        }
        $this->plan[$itemId]['gross'][$b] += $qty;
        $this->plan[$itemId]['pegging'][] = ['type' => $type, 'ref' => $ref, 'qty' => round($qty, 4), 'date' => $date->toDateString()];
    }

    private function addReceipt(int $itemId, Carbon $date, float $qty): void
    {
        $b = $this->bucket($date);
        if ($b === null) {
            return;
        }
        $this->ensure($itemId);
        $this->plan[$itemId]['receipts'][$b] += $qty;
    }

    /** The bucket a date falls in; anything already late lands in the first; beyond the horizon is ignored. */
    private function bucket(Carbon $date): ?int
    {
        $b = (int) floor($this->start->diffInDays($date->copy()->startOfDay(), false) / 7);

        return $b >= $this->weeks ? null : max(0, $b);
    }

    private function bucketDate(int $b): Carbon
    {
        return $this->start->copy()->addWeeks($b);
    }

    private function ensure(int $itemId): void
    {
        if (isset($this->plan[$itemId])) {
            return;
        }

        $zero = array_fill(0, $this->weeks, 0.0);
        $item = FinStockItem::withoutGlobalScopes()->find($itemId);
        $today = now()->toDateString();

        // Usable now: available status, not expired. Lots that expire inside the horizon leave
        // the projection in the week they expire.
        $positions = FinStockPosition::withoutGlobalScopes()->leftJoin('fin_stock_lots as l', 'l.id', '=', 'fin_stock_positions.lot_id')
            ->where('fin_stock_positions.item_id', $itemId)->where('fin_stock_positions.status', FinStockPosition::AVAILABLE)
            ->where('fin_stock_positions.qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', $today))
            ->get(['fin_stock_positions.qty', 'l.expires_on']);
        $expiring = $zero;
        foreach ($positions as $pos) {
            if ($pos->expires_on && ($b = $this->bucket(Carbon::parse($pos->expires_on)->addDay())) !== null) {
                $expiring[$b] += (float) $pos->qty;
            }
        }

        $this->plan[$itemId] = [
            'on_hand' => round((float) $positions->sum('qty'), 4), 'gross' => $zero, 'receipts' => $zero, 'expiring' => $expiring,
            'net' => $zero, 'planned' => $zero, 'projected' => $zero, 'pegging' => [], 'exceptions' => [],
            'shortage_on' => null, 'available_on' => null, 'past_due' => 0.0, 'code' => $item?->code,
        ];
    }

    /** Planning parameters for one item, resolved once: this module's first, then the warehouse profile. */
    private function param(int $itemId): array
    {
        if (isset($this->params[$itemId])) {
            return $this->params[$itemId];
        }

        $own = PlanningParam::query()->where('stock_item_id', $itemId)->first();
        $wms = Schema::hasTable('wms_item_profiles') ? DB::table('wms_item_profiles')->where('stock_item_id', $itemId)->first() : null;
        $item = Item::query()->where('stock_item_id', $itemId)->first();
        $bom = $item && $item->isManufactured() ? $this->boms->applicable($item->id, now()) : null;
        $makeOrBuy = $own?->make_or_buy ?? ($bom ? 'MAKE' : 'BUY');

        $lastPurchase = Schema::hasColumn('purchase_order_items', 'stock_item_id')
            ? DB::table('purchase_order_items as i')->join('purchase_orders as p', 'p.id', '=', 'i.purchase_order_id')
                ->where('i.stock_item_id', $itemId)->whereNull('p.deleted_at')->orderByDesc('p.order_date')->orderByDesc('i.id')
                ->first(['p.supplier_id', 'i.unit_price'])
            : null;

        return $this->params[$itemId] = [
            'item_id' => $item?->id, 'code' => FinStockItem::withoutGlobalScopes()->whereKey($itemId)->value('code'),
            'make_or_buy' => $makeOrBuy, 'bom' => $makeOrBuy === 'MAKE' ? $bom : null,
            'lead' => $own?->lead_time_days ?? ($wms->lead_time_days ?? null),
            'safety' => (float) ($own?->safety_stock ?? ($wms->safety_stock ?? 0)),
            'max' => isset($wms->max_stock) && $wms->max_stock !== null ? (float) $wms->max_stock : null,
            'moq' => $own?->min_order_qty !== null ? (float) $own->min_order_qty : null,
            'multiple' => $own?->order_multiple !== null ? (float) $own->order_multiple : null,
            'supplier_id' => $own?->preferred_supplier_id ?? $lastPurchase?->supplier_id,
            'last_price' => $lastPurchase ? (float) $lastPurchase->unit_price : null,
        ];
    }

    private function timeline(array $p): array
    {
        $out = [];
        for ($b = 0; $b < $this->weeks; $b++) {
            $out[] = ['week' => $this->bucketDate($b)->toDateString(), 'gross' => round($p['gross'][$b], 4), 'receipts' => round($p['receipts'][$b], 4),
                'expiring' => round($p['expiring'][$b], 4), 'net' => round($p['net'][$b], 4), 'planned' => round($p['planned'][$b], 4),
                'projected' => $p['projected'][$b]];
        }

        return $out;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
