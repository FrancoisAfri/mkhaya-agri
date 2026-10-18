<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog as R;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the warehouse assistant (WMS-140 to WMS-142), on the same design as the
 * manufacturing assistant: every answer is computed here, by code, from the company's own records,
 * through the same services the screens use and under the same row-level security, and filtered by
 * what the person asking may see. A language model only words the answer, and never adds a figure.
 *
 * Each answer names its period, warehouse and source records; each recommendation says what it
 * was based on, why, what it assumes, what it could be worth, what to do next and whose approval
 * that needs (WMS-141).
 */
class WarehouseAssistant
{
    /** topic => [question, permissions (any one)] */
    public const TOPICS = [
        'orders_at_risk' => ['Which orders are at risk of being late?', [R::VIEW]],
        'expiring' => ['Show stock expiring in the next 30 days.', [R::VIEW]],
        'supplier_accuracy' => ['Which supplier has the worst delivery accuracy?', [R::RECEIVE, R::PLAN, R::ACCOUNTING]],
        'item_variance' => ['Why is this item showing a stock variance?', [R::VIEW]],
        'fulfil_from' => ['Which warehouse could fulfil this order?', [R::VIEW]],
        'transfer_instead' => ['What stock could we transfer instead of buying?', [R::PLAN, R::MOVE_STOCK]],
        'losses_confirmed' => ['Which losses have been confirmed this month?', [R::ACCOUNTING, R::LOSSES, R::INVESTIGATE]],
    ];

    private const KEYWORDS = [
        'losses_confirmed' => ['loss', 'write-off', 'written off', 'shrinkage'],
        'transfer_instead' => ['transfer instead', 'instead of buying', 'rather than buy', 'transfer'],
        'fulfil_from' => ['fulfil', 'fulfill', 'which warehouse could', 'ship from'],
        'supplier_accuracy' => ['supplier', 'vendor', 'delivery accuracy'],
        'expiring' => ['expir', 'best before', 'shelf life', 'go off'],
        'item_variance' => ['variance', 'discrepanc', 'why is', 'missing stock', 'count wrong'],
        'orders_at_risk' => ['late', 'at risk', 'miss', 'deadline', 'sla', 'overdue'],
    ];

    public function __construct(private readonly ClientVisibility $clients) {}

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

    public function allowed(User $user, string $topic): bool
    {
        return collect(self::TOPICS[$topic][1])->contains(fn ($p) => $user->can($p));
    }

    /** @return array<string,mixed> */
    public function answer(string $question, User $user): array
    {
        $topic = $this->topicFor($question);
        $base = ['topic' => $topic, 'question' => $question, 'refused' => false, 'title' => null, 'period' => null, 'warehouse' => null, 'columns' => [],
            'rows' => [], 'sources' => [], 'flags' => [], 'summary' => '', 'recommendations' => []];
        if ($topic === null) {
            return ['refused' => true, 'summary' => 'I can answer questions like: '.implode(' ', array_column(self::TOPICS, 0))] + $base;
        }
        if (! $this->allowed($user, $topic)) {
            // Saying what exists without saying what it holds: the person learns they need access, nothing else (WMS-142).
            return ['refused' => true, 'summary' => 'That question needs access you do not have; ask your warehouse manager.'] + $base;
        }
        $warehouse = $this->warehouseIn($question);
        $answer = $this->{'answer'.str_replace(' ', '', ucwords(str_replace('_', ' ', $topic)))}($question, $user, $warehouse) + $base;
        $answer['warehouse'] = $warehouse?->code ?? 'All warehouses';

        return $answer;
    }

    // ── Topics ───────────────────────────────────────────────────────────────────────────────

    private function answerOrdersAtRisk(string $q, User $user, ?FinWarehouse $wh): array
    {
        $hours = $this->number($q, 'hour') ?? 24;
        $orders = FulfilmentOrder::query()->whereIn('status', FulfilmentOrder::LIVE)->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', now()->addHours($hours))->when($wh, fn ($x) => $x->where('warehouse_id', $wh->id))
            ->with(['warehouse:id,code', 'lines'])->orderBy('sla_due_at')->limit(50)->get();
        $rows = $orders->map(fn ($o) => ['order' => $o->number, 'warehouse' => $o->warehouse?->code, 'due' => $o->sla_due_at->toIso8601String(),
            'stage' => strtolower(str_replace('_', ' ', $o->status)), 'late' => $o->sla_due_at->isPast() ? 'yes' : 'no',
            'short_lines' => $o->lines->filter(fn ($l) => $l->unallocated() > 0)->count(), 'url' => route('warehouse.outbound.orders.show', $o->uuid)])->all();
        $short = collect($rows)->where('short_lines', '>', 0)->count();

        return ['title' => 'Orders at risk of being late', 'period' => $this->period(now(), now()->addHours($hours), "the next {$hours} hours"),
            'columns' => ['order', 'warehouse', 'due', 'stage', 'late', 'short_lines'], 'rows' => $rows,
            'sources' => ['Fulfilment orders with a dispatch deadline (sla_due_at)', 'Order lines and allocations'],
            'summary' => count($rows) ? count($rows)." order(s) are due within {$hours} hours and not yet dispatched; {$short} of them are not fully allocated." : "No undispatched order is due within {$hours} hours.",
            'recommendations' => $short ? [$this->rec(
                text: "Allocate or substitute the {$short} order(s) missing stock first; the others need picking or packing time.",
                basis: "Live orders due within {$hours} hours", reason: 'An order that is not fully allocated cannot be picked complete.',
                assumptions: ['Deadlines are the dispatch deadlines on the orders, from each warehouse\'s policy.'], impact: "{$short} order(s) may miss their deadline.",
                next: 'Open each order and re-check allocation, or allow partial dispatch.', approval: null, actions: ['TASK'], payload: ['orders' => collect($rows)->where('short_lines', '>', 0)->pluck('order')->values()->all()],
            )] : []];
    }

    private function answerExpiring(string $q, User $user, ?FinWarehouse $wh): array
    {
        $days = $this->number($q, 'day') ?? 30;
        $rows = collect(app(ExpiryWatch::class)->atRisk($wh?->id, $days))->filter(fn ($r) => $this->mayViewItem($r['item_id'], $user))->values();
        $exposure = round($rows->sum('exposure'), 2);
        $unknown = $rows->where('demand_known', false)->count();

        return ['title' => "Stock expiring in the next {$days} days", 'period' => $this->period(now(), now()->addDays($days), "the next {$days} days"),
            'columns' => ['item', 'lot', 'warehouse', 'expires_on', 'days_left', 'qty', 'at_risk_qty', 'exposure'],
            'rows' => $rows->map(fn ($r) => array_intersect_key($r, array_flip(['item', 'lot', 'warehouse', 'expires_on', 'days_left', 'qty', 'at_risk_qty', 'exposure'])) + ['url' => route('warehouse.batches.expiry')])->all(),
            'sources' => ['Lots with an expiry date and stock on hand', 'Last 90 days of issues and dispatches (expected use)'],
            'flags' => $unknown ? [['level' => 'WARNING', 'message' => "{$unknown} lot(s) have no usage history, so how much will be used in time is unknown."]] : [],
            'summary' => $rows->isEmpty() ? "Nothing expires in the next {$days} days." : "{$rows->count()} lot(s) expire in the next {$days} days; {$exposure} of their value is unlikely to be used in time.",
            'recommendations' => $exposure > 0 ? [$this->rec(
                text: 'Sell, transfer or use the lots with value at risk first, earliest expiry first.', basis: "Lots expiring within {$days} days and 90 days of demand",
                reason: 'Expected demand before expiry is less than what is on hand.', assumptions: ['Demand continues at the last 90 days\' daily rate.', 'Stock is issued earliest expiry first.'],
                impact: "Up to {$exposure} could be written off at expiry.", next: 'Review the expiry risk screen and plan promotions or transfers.', approval: null, actions: ['TASK'], payload: []),
            ] : []];
    }

    private function answerSupplierAccuracy(string $q, User $user, ?FinWarehouse $wh): array
    {
        $from = now()->subDays(90)->startOfDay();
        $rows = collect(app(SupplierScorecard::class)->scores($from, now()))->filter(fn ($r) => $r['deliveries'] >= 1)
            ->sortBy(fn ($r) => ($r['on_time_rate'] ?? 1) + ($r['quantity_accuracy'] ?? 1))->values();
        $worst = $rows->first();

        return ['title' => 'Supplier delivery accuracy', 'period' => $this->period($from, now(), 'the last 90 days'),
            'columns' => ['supplier', 'deliveries', 'on_time_rate', 'quantity_accuracy', 'damage_rate', 'rejection_rate'],
            'rows' => $rows->map(fn ($r) => array_intersect_key($r, array_flip(['supplier', 'deliveries', 'on_time_rate', 'quantity_accuracy', 'damage_rate', 'rejection_rate'])))->all(),
            'sources' => ['Goods-received notes and their lines', 'Receipt discrepancies', 'Incoming inspections'],
            'flags' => $rows->filter(fn ($r) => $r['deliveries'] < 5)->isNotEmpty() ? [['level' => 'NOTE', 'message' => 'Some suppliers have fewer than five deliveries; their rates move a lot with one delivery.']] : [],
            'summary' => $worst ? "{$worst['supplier']} has the weakest record: on time ".$this->pct($worst['on_time_rate']).', right quantity '.$this->pct($worst['quantity_accuracy'])." over {$worst['deliveries']} deliveries." : 'No deliveries were received in the last 90 days.',
            'recommendations' => $worst ? [$this->rec(
                text: "Raise delivery performance with {$worst['supplier']} and compare alternatives for their items.", basis: 'Deliveries received in the last 90 days',
                reason: 'The lowest combined on-time and quantity accuracy of all suppliers delivering in the period.', assumptions: ['Delivery dates are measured against the order\'s expected date.'],
                impact: 'Late or short deliveries cause stockouts and urgent purchases.', next: 'Open the supplier scorecard and the buying comparison for their items.', approval: null, actions: ['TASK'], payload: []),
            ] : []];
    }

    private function answerItemVariance(string $q, User $user, ?FinWarehouse $wh): array
    {
        $item = $this->itemIn($q);
        if (! $item || ! $this->mayViewItem($item->id, $user)) {
            return ['refused' => true, 'summary' => 'Name the item by its code, e.g. "Why is item SOAP-01 showing a stock variance?".'];
        }
        $from = now()->subDays(90);
        $lines = DB::table('wms_count_lines as l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')->leftJoin('fin_stock_bins as b', 'b.id', '=', 'l.bin_id')
            ->where('l.item_id', $item->id)->where('c.created_at', '>=', $from)->whereNotNull('l.variance_qty')->where('l.variance_qty', '!=', 0)
            ->orderByDesc('c.id')->limit(20)->get(['c.number', 'c.uuid', 'b.code as bin', 'l.expected_qty', 'l.counted_qty', 'l.variance_qty', 'l.causes', 'l.status']);
        $adjust = DB::table('wms_stock_adjustments as a')->join('wms_reason_codes as r', 'r.id', '=', 'a.reason_code_id')->where('a.item_id', $item->id)
            ->where('a.created_at', '>=', $from)->orderByDesc('a.id')->limit(20)->get(['a.number', 'a.qty_delta', 'a.status', 'r.name as reason']);
        $ledger = collect(app(StockHistory::class)->reconcile($item->id))->filter(fn ($r) => abs($r['difference']) > 0.0001);
        $causes = $lines->flatMap(fn ($l) => collect(json_decode((string) $l->causes, true) ?: [])->pluck('text'))->unique()->values();
        // Investigation details only for investigators (WMS-142): others see that a case exists, not what it says.
        $cases = LossFinding::query()->whereIn('category', LossFinding::WAREHOUSE_CATEGORIES)->where('subject_type', 'STOCK_ITEM')->where('subject_id', $item->id)->get();
        $rows = $lines->map(fn ($l) => ['source' => 'count '.$l->number, 'bin' => $l->bin, 'expected' => (float) $l->expected_qty, 'counted' => (float) $l->counted_qty,
            'variance' => (float) $l->variance_qty, 'status' => $l->status, 'url' => route('warehouse.counts.show', $l->uuid)])
            ->merge($adjust->map(fn ($a) => ['source' => 'adjustment '.$a->number, 'bin' => null, 'expected' => null, 'counted' => null, 'variance' => (float) $a->qty_delta,
                'status' => $a->status, 'reason' => $a->reason]))->values()->all();

        return ['title' => "Stock variances on {$item->code}", 'period' => $this->period($from, now(), 'the last 90 days'),
            'columns' => ['source', 'bin', 'expected', 'counted', 'variance', 'status'], 'rows' => $rows,
            'sources' => ['Count lines and their cause hints', 'Stock adjustments', 'The stock ledger against its balance'],
            'flags' => array_values(array_filter([
                $ledger->isNotEmpty() ? ['level' => 'WARNING', 'message' => 'The ledger and the stored balance disagree for this item; run reconciliation.'] : null,
                $cases->isNotEmpty() ? ['level' => 'NOTE', 'message' => $user->can(R::INVESTIGATE) ? $cases->count().' loss case(s) concern this item: '.$cases->pluck('number')->join(', ').'.' : 'An investigation concerns this item; investigators can see it.'] : null,
            ])),
            'summary' => $rows === [] ? "No count variances or adjustments for {$item->code} in the last 90 days." : count($rows)." variance record(s) for {$item->code}".($causes->isNotEmpty() ? '; likely causes noted: '.$causes->take(3)->join(' ') : '.'),
            'recommendations' => $rows !== [] ? [$this->rec(
                text: "Count {$item->code} wherever it is held, blind, and compare with the cause hints.", basis: 'Count lines and adjustments in the last 90 days',
                reason: 'Repeated variances usually have one cause: a unit of measure, a put-away to the wrong bin, or unrecorded issues.', assumptions: ['Cause hints are leads, not findings.'],
                impact: 'Correcting the cause stops the variance recurring.', next: 'Open a count for this item.', approval: 'A posted variance follows the adjustment approval limits.',
                actions: ['COUNT'], payload: ['item_id' => $item->id], subjectId: $item->id),
            ] : []];
    }

    private function answerFulfilFrom(string $q, User $user, ?FinWarehouse $wh): array
    {
        $order = $this->orderIn($q);
        if (! $order) {
            return ['refused' => true, 'summary' => 'Name the order by its number or reference, e.g. "Which warehouse could fulfil order WO-2026-00012?".'];
        }
        $lines = $order->lines()->with('item:id,code')->get();
        $rows = [];
        foreach (FinWarehouse::query()->where('is_active', true)->get() as $w) {
            $atp = collect(app(StockAvailability::class)->rows($lines->pluck('item_id')->all(), $w->id))->groupBy('item_id')->map(fn ($g) => (float) $g->sum('available_to_promise'));
            $covered = $lines->filter(fn ($l) => ($atp[$l->item_id] ?? 0) + ($w->id === $order->warehouse_id ? (float) $l->qty_allocated : 0) >= (float) $l->qty - (float) $l->qty_cancelled - 1e-6)->count();
            $rows[] = ['warehouse' => $w->code, 'lines_covered' => $covered, 'lines' => $lines->count(), 'complete' => $covered === $lines->count() ? 'yes' : 'no',
                'current' => $w->id === $order->warehouse_id ? 'yes' : 'no'];
        }
        $full = collect($rows)->where('complete', 'yes')->pluck('warehouse');
        $home = collect(app(StockAvailability::class)->rows($lines->pluck('item_id')->all(), $order->warehouse_id))->groupBy('item_id')->map(fn ($g) => (float) $g->sum('available_to_promise'));
        $missing = $lines->map(fn ($l) => ['item' => $l->item?->code, 'qty' => round(max(0.0, (float) $l->qty - (float) $l->qty_cancelled - (float) $l->qty_allocated - (float) ($home[$l->item_id] ?? 0)), 4)])
            ->filter(fn ($l) => $l['qty'] > 0)->values()->all();

        return ['title' => "Where order {$order->number} could be fulfilled", 'period' => $this->period(now(), now(), 'now'),
            'columns' => ['warehouse', 'lines_covered', 'lines', 'complete', 'current'], 'rows' => $rows,
            'sources' => ['Available-to-promise stock per warehouse (on hand, usable, less reservations)'],
            'summary' => $full->isNotEmpty() ? 'Complete from: '.$full->join(', ').'.' : 'No single warehouse holds every line; a split shipment or a transfer would be needed.',
            'recommendations' => $full->isNotEmpty() && ! $full->contains($order->warehouse?->code) ? [$this->rec(
                text: "Fulfil {$order->number} from {$full->first()} or transfer the missing stock to {$order->warehouse?->code}.", basis: 'Stock available to promise right now',
                reason: 'The order\'s own warehouse cannot cover every line.', assumptions: ['Availability can change before the order is allocated.'],
                impact: 'Avoids a late or partial delivery.', next: 'Draft a transfer to the order\'s warehouse.', approval: 'A transfer is approved before it is picked.',
                actions: ['TRANSFER_DRAFT'], payload: ['from' => $full->first(), 'to' => $order->warehouse?->code, 'order' => $order->number, 'lines' => $missing]),
            ] : []];
    }

    private function answerTransferInstead(string $q, User $user, ?FinWarehouse $wh): array
    {
        // Warehouse by warehouse: where an item is below its reorder level, and where the same item
        // sits beyond what that warehouse needs for a month. Company-wide totals would hide this --
        // stock idle in one place makes the company look covered while another place runs short.
        $items = FinStockItem::query()->where('status', FinStockItem::ACTIVE)->where('reorder_level', '>', 0)->get()->keyBy('id');
        $warehouses = FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->get();
        $default = $warehouses->first()?->id;
        $atp = collect(app(StockAvailability::class)->rows($items->keys()->all()))->keyBy(fn ($r) => $r['item_id'].':'.$r['warehouse_id']);
        $monthly = DB::table('fin_stock_movements')->whereIn('item_id', $items->keys())->where('qty', '<', 0)->whereIn('type', ['dispatch', 'sale', 'issue', 'mfg_issue'])
            ->whereNull('reversal_of_id')->where('movement_date', '>=', now()->subDays(90)->toDateString())->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, SUM(ABS(qty)) / 3.0 AS m')->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);
        $rows = [];
        foreach ($items as $item) {
            $stocking = fn ($w) => $w->id === $default || isset($monthly[$item->id.':'.$w->id]);
            foreach ($warehouses as $to) {
                if (! $stocking($to) || ($wh && $wh->id !== $to->id)) {
                    continue;
                }
                $need = round((float) $item->reorder_level - (float) ($atp[$item->id.':'.$to->id]['available_to_promise'] ?? 0), 4);
                foreach ($warehouses as $from) {
                    if ($need <= 0 || $from->id === $to->id) {
                        continue;
                    }
                    $have = (float) ($atp[$item->id.':'.$from->id]['available_to_promise'] ?? 0);
                    $keep = ($stocking($from) ? (float) $item->reorder_level : 0) + (float) ($monthly[$item->id.':'.$from->id]->m ?? 0);
                    $spare = round($have - $keep, 4);
                    if ($spare <= 0 || ! $this->mayViewItem($item->id, $user)) {
                        continue;
                    }
                    $qty = min($need, $spare);
                    $cost = (float) ($item->avg_cost ?: $item->standard_cost);
                    $rows[] = ['item' => $item->code, 'to' => $to->code, 'short_by' => $need, 'spare_in' => $from->code, 'spare_qty' => $spare,
                        'transfer_qty' => $qty, 'saving' => round($qty * $cost, 2)];
                    $need = round($need - $qty, 4);
                }
            }
        }
        $saving = round(array_sum(array_column($rows, 'saving')), 2);

        return ['title' => 'Stock that could be transferred instead of bought', 'period' => $this->period(now()->subDays(90), now(), 'demand of the last 90 days and stock now'),
            'columns' => ['item', 'to', 'short_by', 'spare_in', 'spare_qty', 'transfer_qty', 'saving'], 'rows' => $rows,
            'sources' => ['Stock available to promise in each warehouse', 'Reorder levels', 'Each warehouse\'s last 90 days of issues and dispatches'],
            'summary' => $rows ? count($rows)." transfer(s) would cover shortfalls from stock other warehouses do not need, avoiding about {$saving} of buying." : 'No warehouse that is short holds less than another can spare.',
            'recommendations' => array_map(fn ($r) => $this->rec(
                text: "Transfer {$r['transfer_qty']} {$r['item']} from {$r['spare_in']} to {$r['to']} instead of buying.", basis: 'Stock by warehouse, reorder levels and each warehouse\'s demand',
                reason: "{$r['to']} is {$r['short_by']} below its reorder level; {$r['spare_in']} holds {$r['spare_qty']} beyond its own month of need.",
                assumptions: ['The reorder level applies in each warehouse that stocks the item.', 'Transport cost between warehouses is not included.'],
                impact: "Avoids buying about {$r['saving']}.", next: 'Draft the transfer.', approval: 'A transfer is approved before it is picked.',
                actions: ['TRANSFER_DRAFT'], payload: ['from' => $r['spare_in'], 'to' => $r['to'], 'lines' => [['item' => $r['item'], 'qty' => $r['transfer_qty']]]]), $rows)];
    }

    private function answerLossesConfirmed(string $q, User $user, ?FinWarehouse $wh): array
    {
        [$from, $to] = [now()->startOfMonth(), now()];
        $reg = app(LossControl::class)->register($wh?->id, $from->toDateString(), $to->toDateString());
        $rows = DB::table('wms_stock_adjustments as a')->join('wms_reason_codes as r', 'r.id', '=', 'a.reason_code_id')->join('fin_stock_items as i', 'i.id', '=', 'a.item_id')
            ->join('fin_warehouses as w', 'w.id', '=', 'a.warehouse_id')->where('a.status', 'POSTED')->where('a.qty_delta', '<', 0)->whereIn('r.category', LossControl::LOSS_CATEGORIES)
            ->whereBetween('a.occurred_at', [$from, $to])->when($wh, fn ($x) => $x->where('a.warehouse_id', $wh->id))->orderByDesc('a.value_estimate')->limit(50)
            ->get(['a.number', 'a.uuid', 'i.id as item_id', 'i.code as item', 'w.code as warehouse', 'r.name as reason', 'a.qty_delta', 'a.value_estimate'])
            ->filter(fn ($r) => $this->mayViewItem($r->item_id, $user))
            ->map(fn ($r) => ['adjustment' => $r->number, 'item' => $r->item, 'warehouse' => $r->warehouse, 'reason' => $r->reason, 'qty' => (float) $r->qty_delta,
                'value' => (float) $r->value_estimate, 'url' => route('warehouse.stock.adjustments.show', $r->uuid)])->values()->all();

        return ['title' => 'Confirmed losses this month', 'period' => $this->period($from, $to, 'this month to date'),
            'columns' => ['adjustment', 'item', 'warehouse', 'reason', 'qty', 'value'], 'rows' => $rows,
            'sources' => ['Posted stock adjustments with a loss, damage, quality or count reason', 'Scrapped stock from inspections, returns and recalls'],
            'flags' => $reg['suspected']['total'] > 0 ? [['level' => 'NOTE', 'message' => 'A further '.$reg['suspected']['total'].' is suspected but not confirmed; it is not included.']] : [],
            'summary' => 'Confirmed losses this month: '.$reg['confirmed']['total'].' ('.$reg['confirmed']['adjustments'].' write-offs, '.$reg['confirmed']['scrapped'].' disposals); '.$reg['recoveries']['recovered'].' recovered.',
            'recommendations' => []];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function rec(string $text, string $basis, string $reason, array $assumptions, string $impact, string $next, ?string $approval, array $actions, array $payload, ?int $subjectId = null): array
    {
        return ['text' => $text, 'basis' => $basis, 'reason' => $reason, 'assumptions' => $assumptions, 'impact' => $impact, 'next' => $next,
            'approval' => $approval ?? 'None: this is advice and drafts only.', 'actions' => $actions, 'payload' => $payload, 'subject_id' => $subjectId];
    }

    private function period(Carbon $from, Carbon $to, string $label): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'label' => $label];
    }

    private function number(string $q, string $unit): ?int
    {
        return preg_match('/(\d+)\s*'.$unit.'/i', $q, $m) ? max(1, min((int) $m[1], 365)) : null;
    }

    private function pct(?float $v): string
    {
        return $v === null ? 'unknown' : round($v * 100, 1).'%';
    }

    private function warehouseIn(string $q): ?FinWarehouse
    {
        foreach (FinWarehouse::query()->where('is_active', true)->get(['id', 'code']) as $w) {
            if (preg_match('/\b'.preg_quote($w->code, '/').'\b/i', $q)) {
                return $w;
            }
        }

        return null;
    }

    private function itemIn(string $q): ?FinStockItem
    {
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9._\-\/]{1,40}/', $q, $m);
        $tokens = array_map('strtoupper', $m[0]);

        return $tokens ? FinStockItem::query()->whereIn(DB::raw('UPPER(code)'), $tokens)->orderByRaw('LENGTH(code) DESC')->first() : null;
    }

    private function orderIn(string $q): ?FulfilmentOrder
    {
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9._\-\/]{2,60}/', $q, $m);

        return $m[0] ? FulfilmentOrder::query()->where(fn ($x) => $x->whereIn('number', $m[0])->orWhereIn('external_ref', $m[0]))->first() : null;
    }

    private function mayViewItem(int $itemId, User $user): bool
    {
        $item = FinStockItem::query()->find($itemId);

        return $item !== null && $this->clients->mayView($item, $user);
    }
}
