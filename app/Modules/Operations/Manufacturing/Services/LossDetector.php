<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\LossEvent;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Models\LossRule;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\OrderVariance;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The loss detectors (MFG-012). Each reads posted transactions -- stock movements, order actuals,
 * floor time, downtime, inspections, variances -- compares them with a stated baseline and raises a
 * finding when the rule's threshold is crossed and the estimated impact is worth looking at.
 *
 * Every finding carries the rows it was built from, the baseline it was judged against and how the
 * money was estimated, with a confidence that says how much of that estimate is measured and how
 * much is assumed. A finding already open for the same thing is not raised twice.
 */
class LossDetector
{
    /** Share of stock value a year that holding it costs, for the overstock estimate. */
    private const CARRYING_RATE = 0.20;

    private int $companyId;

    private array $rules = [];

    public function __construct(private readonly LossRules $ruleBook, private readonly NumberSequences $numbers) {}

    /** @return array<string, int> findings raised, by category */
    public function scan(int $companyId, ?string $only = null): array
    {
        $this->companyId = $companyId;
        $this->rules = $this->ruleBook->all($companyId);
        $raised = [];

        foreach (Finding::CATEGORIES as $category) {
            if (($only && $only !== $category) || ! $this->rules[$category]->is_active) {
                continue;
            }
            $method = 'detect'.str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $category))));
            $raised[$category] = 0;
            foreach ($this->{$method}($this->rules[$category]) as $candidate) {
                if ($this->raise($category, $candidate)) {
                    $raised[$category]++;
                }
            }
        }

        return $raised;
    }

    // ── Detectors ────────────────────────────────────────────────────────────────────────────

    /** More material issued than the good and scrapped output should have used. */
    private function detectExcessMaterial(LossRule $rule): iterable
    {
        foreach ($this->finishedOrders($rule) as $order) {
            $made = (float) $order->qty_completed + (float) $order->qty_scrapped;
            $ratio = (float) $order->planned_qty > 0 ? $made / (float) $order->planned_qty : 0;
            foreach ($order->components as $c) {
                if (! $c->isConsumed() || ! $c->stockItem) {
                    continue;
                }
                $expected = round((float) $c->required_qty * $ratio, 4);
                $used = $c->netIssued();
                if ($expected <= 0 || $used <= $expected * (1 + $rule->threshold / 100)) {
                    continue;
                }
                $unit = (float) $c->issued_qty > 0 ? (float) $c->issued_value / (float) $c->issued_qty : $this->unitCost($c->stockItem);
                $excess = round($used - $expected, 4);
                yield [
                    'subject' => ['ORDER', $order->id, "{$order->number} · {$c->stockItem->code}"], 'factory_id' => $order->factory_id,
                    'key' => "ORDER:{$order->id}:{$c->stock_item_id}",
                    'measure' => "{$c->stockItem->code} consumed", 'unit' => $c->uom ?? $c->stockItem->uom,
                    'actual' => $used, 'baseline' => $expected,
                    'basis' => sprintf('BOM quantity for the %s good and scrapped units made, plus %s%% tolerance', $this->num($made), $this->num($rule->threshold)),
                    'evidence' => $this->movements(PO::SOURCE_TYPE, $order->id, $c->stock_item_id),
                    'impact' => $excess * $unit, 'confidence' => 'HIGH',
                    'impact_basis' => sprintf('%s excess × %s issued cost', $this->num($excess), $this->num($unit)),
                    'action' => 'Check the issue records against the floor: over-issue, unrecorded scrap, or a BOM quantity that no longer matches the process.',
                ];
            }
        }
    }

    /** Stock found short at a count, or written down with no recorded cause. */
    private function detectUnexplainedStockLoss(LossRule $rule): iterable
    {
        $rows = DB::table('fin_stock_movements')->where('company_id', $this->companyId)->where('type', 'adjustment')->where('qty', '<', 0)
            ->where(fn ($q) => $q->where('source_type', 'CYCLE_COUNT')->orWhereNull('reason_code'))
            ->whereNull('reversal_of_id')->where('movement_date', '>=', $this->since($rule))
            ->get(['id', 'item_id', 'qty', 'value', 'reference', 'movement_date', 'source_type']);

        foreach ($rows->groupBy('item_id') as $itemId => $group) {
            $value = abs((float) $group->sum('value'));
            if ($value <= $rule->threshold) {
                continue;
            }
            $item = FinStockItem::withoutGlobalScopes()->find($itemId);
            yield [
                'subject' => ['STOCK_ITEM', (int) $itemId, $item?->code ?? "Item {$itemId}"], 'key' => "STOCK_ITEM:{$itemId}",
                'measure' => 'Stock written down without a recorded cause', 'unit' => $item?->uom,
                'actual' => abs((float) $group->sum('qty')), 'baseline' => 0.0,
                'basis' => 'Nothing should go missing between counts; every write-down should name its cause',
                'evidence' => $group->map(fn ($m) => ['type' => 'Stock adjustment', 'reference' => $m->reference, 'date' => (string) $m->movement_date,
                    'qty' => (float) $m->qty, 'value' => (float) $m->value, 'source' => $m->source_type])->values()->all(),
                'impact' => $value, 'confidence' => 'HIGH', 'impact_basis' => 'Value of the write-downs as posted',
                'action' => 'Recount, review access to the location, and check for unrecorded issues, scrap or dispatches.',
            ];
        }
    }

    /** A product losing more of its output to scrap and rejection than the rule allows. */
    private function detectHighScrap(LossRule $rule): iterable
    {
        foreach ($this->finishedOrders($rule)->groupBy('item_id') as $itemId => $orders) {
            $good = (float) $orders->sum('qty_completed');
            $lost = (float) $orders->sum('qty_scrapped') + (float) $orders->sum(fn ($o) => $o->operations->sum('qty_rejected'));
            $total = $good + $lost;
            if ($total <= 0 || $lost / $total * 100 <= $rule->threshold) {
                continue;
            }
            $item = $orders->first()->item;
            $rate = round($lost / $total * 100, 2);
            $excess = $lost - $total * $rule->threshold / 100;
            $unit = $this->productCost($item);
            yield [
                'subject' => ['PRODUCT', (int) $itemId, $item?->stockItem?->code ?? "Product {$itemId}"], 'key' => "PRODUCT:{$itemId}",
                'measure' => 'Scrap and rejection rate', 'unit' => '%', 'actual' => $rate, 'baseline' => $rule->threshold,
                'basis' => 'The loss prevention rule\'s acceptable scrap rate',
                'evidence' => $this->orderEvidence($orders, fn ($o) => ['qty' => (float) $o->qty_scrapped, 'value' => null]),
                'impact' => $excess * $unit, 'confidence' => $unit > 0 ? 'HIGH' : 'LOW',
                'impact_basis' => sprintf('%s units lost above the acceptable rate × %s standard unit cost', $this->num($excess), $this->num($unit)),
                'action' => 'Pareto the scrap reasons by operation and work centre; check the process window, tooling and incoming material.',
            ];
        }
    }

    /** The same product reworked again and again. */
    private function detectRepeatedRework(LossRule $rule): iterable
    {
        $orders = PO::query()->where('qty_reworked', '>', 0)->where('updated_at', '>=', $this->since($rule))->with('item.stockItem')->get();
        foreach ($orders->groupBy('item_id') as $itemId => $group) {
            if ($group->count() < max($rule->min_count, (int) $rule->threshold)) {
                continue;
            }
            $item = $group->first()->item;
            $std = app(StandardCosting::class)->current((int) $itemId);
            $conversion = $std && (float) $std->expected_output > 0
                ? ((float) $std->labour_cost + (float) $std->machine_cost + (float) $std->overhead_cost) / (float) $std->expected_output : 0.0;
            $qty = (float) $group->sum('qty_reworked');
            yield [
                'subject' => ['PRODUCT', (int) $itemId, $item?->stockItem?->code ?? "Product {$itemId}"], 'key' => "PRODUCT:{$itemId}",
                'measure' => 'Orders with rework', 'unit' => 'orders', 'actual' => (float) $group->count(), 'baseline' => (float) $rule->threshold,
                'basis' => "Rework on {$this->num($rule->threshold)} or more orders of one product in {$rule->lookback_days} days is a pattern, not an accident",
                'evidence' => $this->orderEvidence($group, fn ($o) => ['qty' => (float) $o->qty_reworked, 'value' => null]),
                'impact' => $qty * $conversion, 'confidence' => $conversion > 0 ? 'MEDIUM' : 'LOW',
                'impact_basis' => sprintf('%s units reworked × %s standard conversion cost a unit', $this->num($qty), $this->num($conversion)),
                'action' => 'Find the common cause across these orders and correct it at the operation that creates the defect.',
            ];
        }
    }

    /** Machines running longer than the routings plan for the work they do. */
    private function detectAbnormalMachine(LossRule $rule): iterable
    {
        $ops = Op::query()->where('status', Op::COMPLETED)->where('completed_at', '>=', $this->since($rule))->where('is_subcontracted', false)
            ->whereNotNull('work_centre_id')->where('planned_minutes', '>', 0)->with('order:id,number')->get();
        foreach ($ops->groupBy('work_centre_id') as $centreId => $group) {
            $planned = (float) $group->sum('planned_minutes');
            $actual = (float) $group->sum('actual_machine_minutes');
            if ($group->count() < $rule->min_count || $actual <= $planned * (1 + $rule->threshold / 100)) {
                continue;
            }
            $centre = WorkCentre::query()->find($centreId);
            $excess = $actual - $planned;
            yield [
                'subject' => ['WORK_CENTRE', (int) $centreId, $centre?->code ?? "Centre {$centreId}"], 'factory_id' => $centre?->factory_id,
                'key' => "WORK_CENTRE:{$centreId}",
                'measure' => 'Machine minutes', 'unit' => 'min', 'actual' => $actual, 'baseline' => $planned,
                'basis' => sprintf('Routing minutes for the operations completed there, plus %s%%', $this->num($rule->threshold)),
                'evidence' => $group->map(fn (Op $o) => ['type' => 'Operation', 'reference' => "{$o->order?->number} · {$o->sequence} {$o->name}",
                    'date' => $o->completed_at?->toDateString(), 'qty' => (float) $o->actual_machine_minutes, 'value' => (float) $o->planned_minutes])->values()->all(),
                'impact' => $excess / 60 * (float) ($centre?->cost_per_hour ?? 0), 'confidence' => 'MEDIUM',
                'impact_basis' => sprintf('%s excess minutes at %s an hour machine cost. Energy is not metered here, so it is not counted separately.',
                    $this->num($excess), $this->num((float) ($centre?->cost_per_hour ?? 0))),
                'action' => 'Compare the machine\'s cycle time with the routing; check speeds, wear and whether the standard is still right.',
            ];
        }
    }

    /** Unplanned stoppages taking more of a work centre's time than the rule allows. */
    private function detectExcessDowntime(LossRule $rule): iterable
    {
        $events = $this->downtime($rule)->filter(fn (DowntimeEvent $e) => ! $e->reason?->is_planned);
        foreach ($events->groupBy('work_centre_id') as $centreId => $group) {
            $down = (float) $group->sum('minutes');
            $run = $this->runMinutes((int) $centreId, $rule);
            $share = $down + $run > 0 ? $down / ($down + $run) * 100 : 0;
            if ($share <= $rule->threshold) {
                continue;
            }
            $centre = $group->first()->workCentre;
            $excess = $down - ($down + $run) * $rule->threshold / 100;
            $rate = (float) ($centre?->cost_per_hour ?? 0) + (float) ($centre?->labour_rate_per_hour ?? 0);
            yield [
                'subject' => ['WORK_CENTRE', (int) $centreId, $centre?->code ?? "Centre {$centreId}"], 'factory_id' => $centre?->factory_id,
                'key' => "WORK_CENTRE:{$centreId}",
                'measure' => 'Unplanned downtime share', 'unit' => '%', 'actual' => round($share, 2), 'baseline' => $rule->threshold,
                'basis' => 'Unplanned downtime against downtime plus recorded run time',
                'evidence' => $this->downtimeEvidence($group),
                'impact' => $excess / 60 * $rate, 'confidence' => 'MEDIUM',
                'impact_basis' => sprintf('%s minutes above the acceptable share × %s an hour machine and labour cost', $this->num($excess), $this->num($rate)),
                'action' => 'Pareto the stoppages by reason; breakdowns go to maintenance, material and labour waits to planning.',
            ];
        }
    }

    /** Setups and changeovers taking longer than the routings plan. */
    private function detectExcessSetup(LossRule $rule): iterable
    {
        $events = $this->downtime($rule)->filter(fn (DowntimeEvent $e) => in_array($e->reason?->category, ['SETUP', 'CHANGEOVER'], true));
        foreach ($events->groupBy('work_centre_id') as $centreId => $group) {
            $actual = (float) $group->sum('minutes');
            $planned = (float) DB::table('mfg_production_order_operations as o')->join('mfg_routing_operations as r', 'r.id', '=', 'o.routing_operation_id')
                ->where('o.company_id', $this->companyId)->where('o.work_centre_id', $centreId)->where('o.completed_at', '>=', $this->since($rule))
                ->sum('r.setup_minutes');
            if ($actual <= $planned * (1 + $rule->threshold / 100)) {
                continue;
            }
            $centre = $group->first()->workCentre;
            $excess = $actual - $planned;
            $rate = (float) ($centre?->cost_per_hour ?? 0) + (float) ($centre?->labour_rate_per_hour ?? 0);
            yield [
                'subject' => ['WORK_CENTRE', (int) $centreId, $centre?->code ?? "Centre {$centreId}"], 'factory_id' => $centre?->factory_id,
                'key' => "WORK_CENTRE:{$centreId}",
                'measure' => 'Setup and changeover minutes', 'unit' => 'min', 'actual' => $actual, 'baseline' => $planned,
                'basis' => sprintf('Routing setup minutes for the operations completed there, plus %s%%', $this->num($rule->threshold)),
                'evidence' => $this->downtimeEvidence($group),
                'impact' => $excess / 60 * $rate, 'confidence' => 'MEDIUM',
                'impact_basis' => sprintf('%s excess minutes × %s an hour machine and labour cost', $this->num($excess), $this->num($rate)),
                'action' => 'Standardise the changeover (SMED): separate what can be prepared while the machine runs; sequence similar jobs together.',
            ];
        }
    }

    /** Stock that has expired, or has not moved for so long it is unlikely to be used. */
    private function detectExpiredObsolete(LossRule $rule): iterable
    {
        $expired = DB::table('fin_stock_positions as p')->join('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->where('p.company_id', $this->companyId)->where('p.qty', '>', 0)->where('l.expires_on', '<', today()->toDateString())
            ->get(['p.item_id', 'i.code', 'i.uom', 'l.id as lot_id', 'l.lot_number', 'l.expires_on', 'p.qty']);
        foreach ($expired->groupBy('lot_id') as $lotId => $rows) {
            $item = FinStockItem::withoutGlobalScopes()->find($rows->first()->item_id);
            $qty = (float) $rows->sum('qty');
            yield [
                'subject' => ['LOT', (int) $lotId, "{$rows->first()->code} lot {$rows->first()->lot_number}"], 'key' => "LOT:{$lotId}",
                'measure' => 'Expired stock on hand', 'unit' => $rows->first()->uom, 'actual' => $qty, 'baseline' => 0.0,
                'basis' => "Expired on {$rows->first()->expires_on}",
                'evidence' => $rows->map(fn ($r) => ['type' => 'Stock position', 'reference' => $r->lot_number, 'date' => $r->expires_on, 'qty' => (float) $r->qty, 'value' => null])->values()->all(),
                'impact' => $qty * $this->unitCost($item), 'confidence' => 'HIGH', 'impact_basis' => 'Quantity × average cost: its book value',
                'action' => 'Quarantine it, decide disposal or re-test, and check why it was not used first (FEFO, over-ordering, forecast).',
            ];
        }

        $cutoff = today()->subDays((int) $rule->threshold)->toDateString();
        $idle = FinStockItem::query()->where('qty_on_hand', '>', 0)->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.item_id', 'fin_stock_items.id')
                ->whereIn('m.type', ['issue', 'mfg_issue', 'sale_issue', 'dispatch', 'transfer'])->where('m.movement_date', '>=', $cutoff))
            ->whereExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.item_id', 'fin_stock_items.id')->where('m.movement_date', '<', $cutoff))
            ->get();
        foreach ($idle as $item) {
            yield [
                'subject' => ['STOCK_ITEM', $item->id, $item->code], 'key' => "STOCK_ITEM:{$item->id}:obsolete",
                'measure' => 'Stock with no movement', 'unit' => $item->uom, 'actual' => (float) $item->qty_on_hand, 'baseline' => 0.0,
                'basis' => "Nothing used, sold or moved for {$this->num($rule->threshold)} days",
                'evidence' => [['type' => 'Stock item', 'reference' => $item->code, 'date' => null, 'qty' => (float) $item->qty_on_hand, 'value' => null]],
                'impact' => (float) $item->qty_on_hand * $this->unitCost($item), 'confidence' => 'LOW',
                'impact_basis' => 'Book value at risk. Whether it is lost depends on whether it can still be used or sold.',
                'action' => 'Confirm with engineering and sales whether it has a use; if not, sell, return or write it off and stop reordering it.',
            ];
        }
    }

    /** More on hand than the usage justifies, or than the warehouse's maximum. */
    private function detectOverstock(LossRule $rule): iterable
    {
        $since = $this->since($rule);
        $weeks = max(1, $rule->lookback_days / 7);
        $usage = DB::table('fin_stock_movements')->where('company_id', $this->companyId)->whereIn('type', ['issue', 'mfg_issue', 'sale_issue', 'dispatch'])
            ->where('movement_date', '>=', $since)->groupBy('item_id')->selectRaw('item_id, SUM(ABS(qty)) AS used')->pluck('used', 'item_id');
        $max = Schema::hasTable('wms_item_profiles')
            ? DB::table('wms_item_profiles')->where('company_id', $this->companyId)->whereNotNull('max_stock')->pluck('max_stock', 'stock_item_id') : collect();

        foreach (FinStockItem::query()->where('qty_on_hand', '>', 0)->where('is_active', true)->get() as $item) {
            $onHand = (float) $item->qty_on_hand;
            $weekly = (float) ($usage[$item->id] ?? 0) / $weeks;
            $ceiling = isset($max[$item->id]) ? (float) $max[$item->id] : ($weekly > 0 ? $weekly * $rule->threshold : null);
            if ($ceiling === null || $onHand <= $ceiling) {
                continue;   // No usage and no maximum: that is the obsolete detector's question.
            }
            $excess = $onHand - $ceiling;
            $purchases = DB::table('fin_stock_movements')->where('item_id', $item->id)->whereIn('type', ['receipt', 'purchase', 'grn'])
                ->where('movement_date', '>=', $since)->orderBy('movement_date')->limit(20)->get(['reference', 'movement_date', 'qty', 'value']);
            yield [
                'subject' => ['STOCK_ITEM', $item->id, $item->code], 'key' => "STOCK_ITEM:{$item->id}:overstock",
                'measure' => 'Stock on hand', 'unit' => $item->uom, 'actual' => $onHand, 'baseline' => round($ceiling, 4),
                'basis' => isset($max[$item->id]) ? 'The warehouse\'s maximum stock'
                    : sprintf('%s weeks of the last %d days\' usage (%s a week)', $this->num($rule->threshold), $rule->lookback_days, $this->num($weekly)),
                'evidence' => $purchases->map(fn ($p) => ['type' => 'Receipt', 'reference' => $p->reference, 'date' => (string) $p->movement_date, 'qty' => (float) $p->qty, 'value' => (float) $p->value])->all()
                    ?: [['type' => 'Stock item', 'reference' => $item->code, 'date' => null, 'qty' => $onHand, 'value' => null]],
                'impact' => $excess * $this->unitCost($item) * self::CARRYING_RATE, 'confidence' => 'LOW',
                'impact_basis' => sprintf('%s excess × %s cost × %d%% a year to carry. The capital itself is tied up, not lost.',
                    $this->num($excess), $this->num($this->unitCost($item)), (int) (self::CARRYING_RATE * 100)),
                'action' => 'Stop or reduce open purchase orders, review the reorder point and order quantity, and the forecast that drove them.',
            ];
        }
    }

    /** Orders raised with less time to their due date than they take to make. */
    private function detectRushOrders(LossRule $rule): iterable
    {
        $orders = PO::query()->where('created_at', '>=', $this->since($rule))->whereNotIn('status', [PO::CANCELLED, PO::REJECTED])
            ->with(['item.stockItem', 'operations.workCentre'])->get()
            ->filter(function (PO $o) {
                $leadDays = max(1, (int) ceil((float) $o->operations->sum('planned_minutes') / 480));

                return Carbon::parse($o->created_at)->startOfDay()->diffInDays(Carbon::parse($o->due_date), false) < $leadDays;
            });
        foreach ($orders->groupBy('item_id') as $itemId => $group) {
            if ($group->count() < max($rule->min_count, (int) $rule->threshold)) {
                continue;
            }
            $premium = $group->sum(fn (PO $o) => $o->operations->sum(fn (Op $op) => (float) $op->planned_labour_minutes / 60 * (float) ($op->workCentre?->labour_rate_per_hour ?? 0))) * 0.5;
            $item = $group->first()->item;
            yield [
                'subject' => ['PRODUCT', (int) $itemId, $item?->stockItem?->code ?? "Product {$itemId}"], 'key' => "PRODUCT:{$itemId}:rush",
                'measure' => 'Orders raised inside their own lead time', 'unit' => 'orders', 'actual' => (float) $group->count(), 'baseline' => (float) $rule->threshold,
                'basis' => 'Days from raising to due date against the routing\'s working days',
                'evidence' => $this->orderEvidence($group, fn ($o) => ['qty' => (float) $o->planned_qty, 'value' => null]),
                'impact' => $premium, 'confidence' => 'LOW',
                'impact_basis' => 'Assumes the planned labour is worked at a 50% overtime premium; expediting and freight are not recorded here.',
                'action' => 'Review why demand reached production late: forecast, sales order entry, or MRP parameters for this product.',
            ];
        }
    }

    /** One supplier's goods failing inspection again and again. */
    private function detectSupplierQuality(LossRule $rule): iterable
    {
        $failed = Inspection::query()->whereNotNull('supplier_id')->where('result', Inspection::FAILED)
            ->where('inspected_at', '>=', $this->since($rule))->get();
        foreach ($failed->groupBy('supplier_id') as $supplierId => $group) {
            if ($group->count() < max($rule->min_count, (int) $rule->threshold)) {
                continue;
            }
            $name = DB::table('suppliers')->where('id', $supplierId)->value('company_name');
            $value = $group->sum(fn (Inspection $i) => (float) $i->qty * $this->unitCost(FinStockItem::withoutGlobalScopes()->find($i->stock_item_id)));
            yield [
                'subject' => ['SUPPLIER', (int) $supplierId, $name ?? "Supplier {$supplierId}"], 'key' => "SUPPLIER:{$supplierId}",
                'measure' => 'Failed inspections', 'unit' => 'inspections', 'actual' => (float) $group->count(), 'baseline' => (float) $rule->threshold,
                'basis' => "{$this->num($rule->threshold)} or more failures in {$rule->lookback_days} days",
                'evidence' => $group->map(fn (Inspection $i) => ['type' => 'Inspection', 'reference' => $i->number, 'date' => $i->inspected_at?->toDateString(),
                    'qty' => (float) $i->qty, 'value' => null])->values()->all(),
                'impact' => $value, 'confidence' => 'MEDIUM',
                'impact_basis' => 'Quantity in the failed inspections at cost; what is recovered from the supplier is not yet known.',
                'action' => 'Raise a supplier corrective action request, tighten incoming inspection, and claim the cost back.',
            ];
        }
    }

    /** A product whose orders keep closing over their standard cost. */
    private function detectOverStandard(LossRule $rule): iterable
    {
        $rows = OrderVariance::query()->where('kind', 'TOTAL')->where('calculated_at', '>=', $this->since($rule))
            ->with('order.item.stockItem')->get()
            ->filter(function (OrderVariance $v) use ($rule) {
                $standard = (float) ($v->inputs['standard_for_output'] ?? 0);

                return $v->amount !== null && $standard > 0 && (float) $v->amount / $standard * 100 > $rule->threshold;
            });
        foreach ($rows->groupBy(fn ($v) => $v->order?->item_id) as $itemId => $group) {
            if (! $itemId || $group->count() < $rule->min_count) {
                continue;
            }
            $item = $group->first()->order->item;
            $over = (float) $group->sum('amount');
            $standard = (float) $group->sum(fn ($v) => (float) ($v->inputs['standard_for_output'] ?? 0));
            yield [
                'subject' => ['PRODUCT', (int) $itemId, $item?->stockItem?->code ?? "Product {$itemId}"], 'key' => "PRODUCT:{$itemId}:standard",
                'measure' => 'Cost over standard', 'unit' => '%', 'actual' => round($over / $standard * 100, 2), 'baseline' => $rule->threshold,
                'basis' => sprintf('Approved standard cost; %d orders over by more than %s%%', $group->count(), $this->num($rule->threshold)),
                'evidence' => $group->map(fn (OrderVariance $v) => ['type' => 'Production order', 'reference' => $v->order?->number,
                    'date' => $v->calculated_at?->toDateString(), 'qty' => null, 'value' => (float) $v->amount])->values()->all(),
                'impact' => $over, 'confidence' => 'HIGH', 'impact_basis' => 'Sum of the closed orders\' total variances',
                'action' => 'Read the variance breakdown on these orders; if the cause is a stale standard, revise it, otherwise fix the process.',
            ];
        }
    }

    // ── Raising ──────────────────────────────────────────────────────────────────────────────

    private function raise(string $category, array $c): ?Finding
    {
        $rule = $this->rules[$category];
        $impact = round(max(0.0, (float) $c['impact']), 2);
        if ($impact < $rule->min_impact) {
            return null;
        }
        $key = "{$category}:{$c['key']}";
        // Already being looked at, or decided within the window: do not raise it again.
        $exists = Finding::query()->where('dedupe_key', $key)
            ->where(fn ($q) => $q->whereIn('status', Finding::OPEN)->orWhere('detected_at', '>=', now()->subDays($rule->lookback_days)))->exists();
        if ($exists) {
            return null;
        }

        return DB::transaction(function () use ($category, $c, $rule, $impact, $key) {
            [$type, $id, $label] = $c['subject'];
            $finding = Finding::create([
                'company_id' => $this->companyId, 'number' => $this->numbers->next($this->companyId, 'LOSS_FINDING'),
                'category' => $category, 'status' => Finding::DETECTED, 'factory_id' => $c['factory_id'] ?? null,
                'subject_type' => $type, 'subject_id' => $id, 'subject_label' => $label,
                'period_from' => $this->since($rule), 'period_to' => today()->toDateString(),
                'measure' => $c['measure'], 'unit' => $c['unit'] ?? null, 'actual_value' => round((float) $c['actual'], 4),
                'baseline_value' => isset($c['baseline']) ? round((float) $c['baseline'], 4) : null, 'baseline_basis' => $c['basis'],
                'evidence' => array_slice(array_values($c['evidence']), 0, 200), 'estimated_impact' => $impact, 'confidence' => $c['confidence'],
                'impact_basis' => $c['impact_basis'], 'recommended_action' => $c['action'],
                'due_date' => today()->addDays($rule->due_days)->toDateString(), 'dedupe_key' => $key, 'detected_at' => now(),
            ]);
            LossEvent::create(['company_id' => $this->companyId, 'finding_id' => $finding->id, 'action' => 'DETECTED', 'to_status' => Finding::DETECTED,
                'notes' => "{$c['measure']}: {$this->num((float) $c['actual'])} against {$this->num((float) ($c['baseline'] ?? 0))}",
                'detail' => ['estimated_impact' => $impact, 'confidence' => $c['confidence']], 'acted_at' => now()]);

            return $finding;
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function since(LossRule $rule): string
    {
        return today()->subDays($rule->lookback_days)->toDateString();
    }

    /** Orders finished in the rule's window. */
    private function finishedOrders(LossRule $rule): Collection
    {
        return PO::query()->whereIn('status', [PO::COMPLETED, PO::CLOSED])->where('completed_at', '>=', $this->since($rule))
            ->with(['components.stockItem', 'operations', 'item.stockItem'])->get();
    }

    private function downtime(LossRule $rule): Collection
    {
        return DowntimeEvent::query()->whereNotNull('work_centre_id')->whereNotNull('ended_at')->where('started_at', '>=', $this->since($rule))
            ->with(['reason', 'workCentre'])->get();
    }

    private function runMinutes(int $centreId, LossRule $rule): float
    {
        return (float) OperationTimeLog::query()->join('mfg_production_order_operations as o', 'o.id', '=', 'mfg_operation_time_logs.order_operation_id')
            ->where('o.work_centre_id', $centreId)->where('mfg_operation_time_logs.started_at', '>=', $this->since($rule))
            ->sum('mfg_operation_time_logs.minutes');
    }

    private function movements(string $sourceType, int $sourceId, ?int $itemId = null): array
    {
        return DB::table('fin_stock_movements')->where('source_type', $sourceType)->where('source_id', $sourceId)
            ->when($itemId, fn ($q) => $q->where('item_id', $itemId))->orderBy('movement_date')->orderBy('id')->limit(100)
            ->get(['type', 'reference', 'movement_date', 'qty', 'value'])
            ->map(fn ($m) => ['type' => "Stock {$m->type}", 'reference' => $m->reference, 'date' => (string) $m->movement_date, 'qty' => (float) $m->qty, 'value' => (float) $m->value])
            ->all();
    }

    private function orderEvidence(Collection $orders, callable $values): array
    {
        return $orders->map(fn (PO $o) => ['type' => 'Production order', 'reference' => $o->number,
            'date' => ($o->completed_at ?? $o->created_at)?->toDateString()] + $values($o))->values()->all();
    }

    private function downtimeEvidence(Collection $events): array
    {
        return $events->map(fn (DowntimeEvent $e) => ['type' => 'Downtime', 'reference' => trim(($e->reason?->code ?? '').' '.($e->notes ?? '')),
            'date' => $e->started_at?->toDateString(), 'qty' => (float) $e->minutes, 'value' => null])->values()->all();
    }

    private function unitCost(?FinStockItem $item): float
    {
        return $item ? ((float) $item->avg_cost ?: (float) $item->standard_cost) : 0.0;
    }

    private function productCost(?Item $item): float
    {
        if (! $item) {
            return 0.0;
        }
        $std = app(StandardCosting::class)->current($item->id);

        return $std ? (float) $std->unit_cost : $this->unitCost($item->stockItem);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
