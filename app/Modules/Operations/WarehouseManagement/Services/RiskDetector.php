<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\Manufacturing\Models\LossEvent;
use App\Modules\Operations\Manufacturing\Models\LossFinding as Finding;
use App\Modules\Operations\Manufacturing\Models\LossRule;
use App\Modules\Operations\Manufacturing\Services\LossRules;
use App\Modules\Operations\Manufacturing\Services\NumberSequences;
use Illuminate\Support\Facades\DB;

/**
 * The warehouse's loss detectors (WMS-110) -- the Profit Protection Engine's eyes.
 *
 * Each reads posted records, compares them with a threshold the company sets, and raises a finding
 * on Manufacturing's loss register (MFG-012) so it is investigated the same way as any other loss.
 * A finding is a lead, never a conclusion: it names a process, an item or a place -- not a person --
 * says exactly what it saw and what it was compared with, keeps the rule as it stood that day, and
 * carries a confidence that says how much of its money figure is measured and how much assumed.
 */
class RiskDetector
{
    private int $companyId;

    /** @var array<string, LossRule> */
    private array $rules = [];

    public function __construct(private readonly LossRules $ruleBook, private readonly NumberSequences $numbers) {}

    /** @return array<string, int> findings raised, by category */
    public function scan(int $companyId, ?string $only = null): array
    {
        $this->companyId = $companyId;
        $raised = [];
        foreach (array_keys(LossRules::WAREHOUSE_DEFAULTS) as $category) {
            if ($category === 'WH_MANUAL' || ($only && $only !== $category)) {
                continue;
            }
            $rule = $this->rules[$category] = $this->ruleBook->for($companyId, $category);
            if (! $rule->is_active) {
                continue;
            }
            $method = 'detect'.str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', substr($category, 3)))));
            $raised[$category] = 0;
            foreach ($this->{$method}($rule) as $candidate) {
                if ($this->raise($category, $candidate)) {
                    $raised[$category]++;
                }
            }
        }

        return $raised;
    }

    // ── Detectors ────────────────────────────────────────────────────────────────────────────

    private function detectRepeatedAdjustments(LossRule $rule): array
    {
        $rows = DB::table('wms_stock_adjustments as a')->join('fin_stock_items as i', 'i.id', '=', 'a.item_id')->leftJoin('fin_stock_bins as b', 'b.id', '=', 'a.bin_id')
            ->where('a.company_id', $this->companyId)->whereIn('a.status', ['POSTED', 'PENDING_APPROVAL'])->where('a.created_at', '>=', $this->since($rule))
            ->groupBy('a.item_id', 'a.bin_id', 'a.warehouse_id', 'i.code', 'b.code')
            ->havingRaw('COUNT(*) >= ?', [max((int) $rule->threshold, (int) $rule->min_count)])
            ->selectRaw("a.item_id, a.bin_id, a.warehouse_id, i.code, b.code AS bin, COUNT(*) AS n, SUM(CASE WHEN a.qty_delta < 0 THEN a.value_estimate ELSE 0 END) AS lost,
                string_agg(a.number, ', ' ORDER BY a.id) AS numbers")->get();

        return $rows->map(fn ($r) => [
            'key' => "{$r->item_id}:{$r->bin_id}", 'subject' => ['STOCK_ITEM', (int) $r->item_id, trim("{$r->code} ".($r->bin ? "in {$r->bin}" : ''))],
            'warehouse_id' => $r->warehouse_id, 'measure' => 'Adjustments in the window', 'unit' => 'adjustments', 'actual' => (int) $r->n,
            'baseline' => (float) $rule->threshold, 'basis' => "Rule: {$rule->threshold} or more adjustments of one item in one place within {$rule->lookback_days} days",
            'evidence' => ['adjustments' => $r->numbers], 'impact' => (float) $r->lost, 'confidence' => 'MEDIUM',
            'impact_basis' => 'Value of the stock written down by these adjustments; gains are not netted off.',
            'action' => 'Count the place, compare with the adjustment reasons, and check whether the same cause keeps recurring.',
        ])->all();
    }

    private function detectHighValueAdjustment(LossRule $rule): array
    {
        $history = DB::table('wms_stock_adjustments')->where('company_id', $this->companyId)->where('value_estimate', '>', 0)
            ->where('created_at', '>=', now()->subDays($rule->lookback_days * 4))->pluck('value_estimate')->map(fn ($v) => (float) $v)->sort()->values();
        if ($history->isEmpty()) {
            return [];
        }
        $median = (float) $history[(int) floor(($history->count() - 1) / 2)];
        $limit = max($median * (float) $rule->threshold, (float) $rule->min_impact);
        // A median of few adjustments says little; the finding says how much history it stood on.
        $confidence = $history->count() >= 20 ? 'MEDIUM' : 'LOW';

        return DB::table('wms_stock_adjustments as a')->join('fin_stock_items as i', 'i.id', '=', 'a.item_id')
            ->where('a.company_id', $this->companyId)->where('a.created_at', '>=', $this->since($rule))->where('a.value_estimate', '>', $limit)
            ->whereIn('a.status', ['POSTED', 'PENDING_APPROVAL'])->get(['a.id', 'a.number', 'a.warehouse_id', 'a.value_estimate', 'a.qty_delta', 'i.code'])
            ->map(fn ($a) => [
                'key' => "adj:{$a->id}", 'subject' => ['ADJUSTMENT', (int) $a->id, "{$a->number} ({$a->code})"], 'warehouse_id' => $a->warehouse_id,
                'measure' => 'Adjustment value', 'unit' => 'currency', 'actual' => (float) $a->value_estimate, 'baseline' => $median,
                'basis' => "Typical (median) adjustment over the last ".($rule->lookback_days * 4)." days, from {$history->count()} adjustments; rule: more than {$rule->threshold}×",
                'evidence' => ['adjustment' => $a->number, 'qty' => (float) $a->qty_delta], 'impact' => (float) $a->qty_delta < 0 ? (float) $a->value_estimate : 0.0,
                'confidence' => $confidence, 'impact_basis' => 'The value of this one adjustment where it reduced stock.',
                'action' => 'Confirm the adjustment against the evidence attached to it and the person who approved it.',
            ])->all();
    }

    private function detectDuplicateDocument(LossRule $rule): array
    {
        $days = max(1, (int) $rule->threshold);
        $receipts = DB::table('goods_received_items as a')->join('goods_received_items as b', fn ($j) => $j->on('a.purchase_order_item_id', '=', 'b.purchase_order_item_id')->whereColumn('a.id', '<', 'b.id'))
            ->join('goods_received_notes as ga', 'ga.id', '=', 'a.goods_received_note_id')->join('goods_received_notes as gb', 'gb.id', '=', 'b.goods_received_note_id')
            ->where('ga.company_id', $this->companyId)->whereColumn('a.received_qty', 'b.received_qty')->where('a.received_qty', '>', 0)
            ->whereColumn('ga.id', '!=', 'gb.id')->where('gb.created_at', '>=', $this->since($rule))
            ->whereRaw('ABS(EXTRACT(EPOCH FROM (gb.created_at - ga.created_at))) <= ?', [$days * 86400])
            ->get(['a.id', 'b.id as other', 'ga.grn_number as first', 'gb.grn_number as second', 'a.received_qty', 'a.unit_cost', 'a.stock_item_id', 'ga.warehouse_id']);
        $out = $receipts->map(fn ($r) => [
            'key' => "grn:{$r->id}:{$r->other}", 'subject' => ['GRN', (int) $r->other, "{$r->second} repeats {$r->first}"], 'warehouse_id' => $r->warehouse_id,
            'measure' => 'Same order line, same quantity, received twice', 'unit' => 'units', 'actual' => (float) $r->received_qty, 'baseline' => 0,
            'basis' => "Rule: two receipts of the same order line and quantity within {$days} day(s)", 'evidence' => ['receipts' => [$r->first, $r->second]],
            'impact' => (float) $r->received_qty * (float) $r->unit_cost, 'confidence' => 'MEDIUM',
            'impact_basis' => 'Value of the second receipt, which would be paid for twice if it never arrived.',
            'action' => 'Check whether two deliveries really came; reverse the duplicate receipt if not.',
        ])->all();

        $shipments = DB::table('wms_shipments as s1')->join('wms_shipments as s2', fn ($j) => $j->on('s1.order_id', '=', 's2.order_id')->whereColumn('s1.id', '<', 's2.id'))
            ->where('s1.company_id', $this->companyId)->where('s2.created_at', '>=', $this->since($rule))
            ->whereRaw('ABS(EXTRACT(EPOCH FROM (s2.created_at - s1.created_at))) <= 86400')
            ->whereRaw('(SELECT COALESCE(SUM(pc.qty),0) FROM wms_shipment_packages sp JOIN wms_package_contents pc ON pc.package_id = sp.package_id WHERE sp.shipment_id = s1.id)
                      = (SELECT COALESCE(SUM(pc.qty),0) FROM wms_shipment_packages sp JOIN wms_package_contents pc ON pc.package_id = sp.package_id WHERE sp.shipment_id = s2.id)')
            ->get(['s1.number as first', 's2.number as second', 's2.id', 's1.warehouse_id']);
        foreach ($shipments as $s) {
            $out[] = ['key' => "shp:{$s->id}", 'subject' => ['SHIPMENT', (int) $s->id, "{$s->second} repeats {$s->first}"], 'warehouse_id' => $s->warehouse_id,
                'measure' => 'Two shipments of the same quantity for one order within a day', 'unit' => 'shipments', 'actual' => 2, 'baseline' => 1,
                'basis' => 'Rule: the same order shipped twice, the same quantity, within a day', 'evidence' => ['shipments' => [$s->first, $s->second]],
                'impact' => 0, 'confidence' => 'LOW', 'impact_basis' => 'Not estimated: a split delivery can look the same.', 'action' => 'Confirm both shipments with the carrier and the customer.'];
        }

        return $out;
    }

    private function detectUnusualReturns(LossRule $rule): array
    {
        $since = $this->since($rule);
        $returned = DB::table('wms_customer_return_lines as l')->join('wms_customer_returns as r', 'r.id', '=', 'l.return_id')->join('fin_stock_items as i', 'i.id', '=', 'l.item_id')
            ->where('r.company_id', $this->companyId)->where('r.received_at', '>=', $since)->where('l.qty_received', '>', 0)
            ->groupBy('l.item_id', 'i.code')->selectRaw('l.item_id, i.code, SUM(l.qty_received) AS qty, SUM(l.qty_received * l.unit_cost) AS value, COUNT(*) AS n, string_agg(r.number, \', \') AS numbers')->get();
        $sent = DB::table('fin_stock_movements')->where('company_id', $this->companyId)->where('type', 'dispatch')->where('created_at', '>=', $since)
            ->groupBy('item_id')->selectRaw('item_id, SUM(ABS(qty)) AS qty')->pluck('qty', 'item_id');
        $out = [];
        foreach ($returned as $r) {
            $pct = (float) ($sent[$r->item_id] ?? 0) > 0 ? (float) $r->qty / (float) $sent[$r->item_id] * 100 : 100.0;
            if ($pct >= (float) $rule->threshold && (int) $r->n >= (int) $rule->min_count) {
                $out[] = ['key' => "ret:{$r->item_id}", 'subject' => ['STOCK_ITEM', (int) $r->item_id, $r->code], 'warehouse_id' => null,
                    'measure' => 'Returned as a share of dispatched', 'unit' => '%', 'actual' => round($pct, 1), 'baseline' => (float) $rule->threshold,
                    'basis' => "Rule: {$rule->threshold}% or more of what was dispatched in {$rule->lookback_days} days came back, on at least {$rule->min_count} returns",
                    'evidence' => ['returns' => $r->numbers, 'returned' => (float) $r->qty, 'dispatched' => (float) ($sent[$r->item_id] ?? 0)],
                    'impact' => (float) $r->value, 'confidence' => 'MEDIUM', 'impact_basis' => 'Cost of the goods returned; resale value of what was restocked is not deducted.',
                    'action' => 'Look at the return reasons and conditions: product quality, picking errors or abuse of the returns process.'];
            }
        }
        $reversals = DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')->where('m.company_id', $this->companyId)
            ->whereNotNull('m.reversal_of_id')->where('m.created_at', '>=', $since)->groupBy('m.item_id', 'i.code')
            ->havingRaw('COUNT(*) >= ?', [(int) $rule->min_count])->selectRaw('m.item_id, i.code, COUNT(*) AS n, SUM(ABS(m.value)) AS value')->get();
        foreach ($reversals as $r) {
            $out[] = ['key' => "rev:{$r->item_id}", 'subject' => ['STOCK_ITEM', (int) $r->item_id, $r->code], 'warehouse_id' => null,
                'measure' => 'Reversed movements', 'unit' => 'reversals', 'actual' => (int) $r->n, 'baseline' => (float) $rule->min_count,
                'basis' => "Rule: {$rule->min_count} or more reversals of the same item within {$rule->lookback_days} days", 'evidence' => ['reversals' => (int) $r->n],
                'impact' => 0, 'confidence' => 'LOW', 'impact_basis' => 'Not estimated: a reversal undoes a movement; repeated ones point to process errors.',
                'action' => 'Read why each movement was reversed.'];
        }

        return $out;
    }

    private function detectRepeatedShortPicks(LossRule $rule): array
    {
        return DB::table('fin_stock_movements as m')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')->leftJoin('fin_stock_bins as b', 'b.id', '=', 'm.bin_id')
            ->where('m.company_id', $this->companyId)->where('m.reason_code', 'SHORT_PICK')->where('m.created_at', '>=', $this->since($rule))
            ->groupBy('m.item_id', 'm.bin_id', 'm.warehouse_id', 'i.code', 'b.code', 'i.avg_cost', 'i.standard_cost')
            ->havingRaw('COUNT(*) >= ?', [max((int) $rule->threshold, (int) $rule->min_count)])
            ->selectRaw('m.item_id, m.bin_id, m.warehouse_id, i.code, b.code AS bin, COUNT(*) AS n, SUM(ABS(m.qty)) AS qty, COALESCE(NULLIF(i.avg_cost,0), i.standard_cost) AS cost')
            ->get()->map(fn ($r) => [
                'key' => "short:{$r->item_id}:{$r->bin_id}", 'subject' => ['BIN', (int) $r->bin_id, trim("{$r->bin} ({$r->code})")], 'warehouse_id' => $r->warehouse_id,
                'measure' => 'Short picks from one place', 'unit' => 'short picks', 'actual' => (int) $r->n, 'baseline' => (float) $rule->threshold,
                'basis' => "Rule: {$rule->threshold} or more short picks from the same place within {$rule->lookback_days} days",
                'evidence' => ['held_qty' => (float) $r->qty], 'impact' => (float) $r->qty * (float) $r->cost, 'confidence' => 'MEDIUM',
                'impact_basis' => 'Value of the stock the pickers could not find, put on hold for counting.',
                'action' => 'Count the place; check put-away into it and whether its label is right.',
            ])->all();
    }

    private function detectUnexpectedLocation(LossRule $rule): array
    {
        return DB::table('fin_stock_movements as m')->join('fin_stock_bins as tb', 'tb.id', '=', 'm.to_bin_id')->join('fin_stock_items as i', 'i.id', '=', 'm.item_id')
            ->where('m.company_id', $this->companyId)->where('m.source_type', 'BIN_MOVE')->whereIn('tb.kind', ['DISPATCH', 'IN_TRANSIT', 'RETURNS'])
            ->where('m.created_at', '>=', $this->since($rule))->where('m.qty', '<', 0)
            ->get(['m.id', 'm.uuid', 'm.qty', 'm.warehouse_id', 'tb.code as bin', 'tb.kind', 'i.code', 'i.avg_cost', 'i.standard_cost', 'm.reference'])
            ->map(fn ($m) => [
                'key' => "loc:{$m->id}", 'subject' => ['BIN', null, "{$m->code} moved into {$m->bin}"], 'warehouse_id' => $m->warehouse_id,
                'measure' => 'Manual move into a '.strtolower(str_replace('_', ' ', $m->kind)).' area', 'unit' => 'units', 'actual' => abs((float) $m->qty), 'baseline' => 0,
                'basis' => 'Rule: stock should reach dispatch, transit and returns areas through a shipment, a transfer or a return, not a bin move',
                'evidence' => ['movement' => $m->uuid, 'reference' => $m->reference],
                'impact' => abs((float) $m->qty) * ((float) $m->avg_cost ?: (float) $m->standard_cost), 'confidence' => 'LOW',
                'impact_basis' => 'Value of the stock moved; most such moves are innocent staging.', 'action' => 'Confirm the stock is still there or left on a recorded shipment.',
            ])->all();
    }

    private function detectDispatchMismatch(LossRule $rule): array
    {
        return DB::table('wms_order_lines as ol')->join('wms_orders as o', 'o.id', '=', 'ol.order_id')->join('fin_stock_items as i', 'i.id', '=', 'ol.item_id')
            ->where('o.company_id', $this->companyId)->where('ol.qty_dispatched', '>', 0)->where('o.updated_at', '>=', $this->since($rule))
            ->selectRaw('ol.id, o.number, o.warehouse_id, i.code, ol.qty_dispatched, COALESCE(NULLIF(i.avg_cost,0), i.standard_cost) AS cost,
                (SELECT COALESCE(SUM(pc.qty), 0) FROM wms_package_contents pc JOIN wms_shipment_packages sp ON sp.package_id = pc.package_id WHERE pc.order_line_id = ol.id) AS shipped')
            ->get()->filter(fn ($r) => abs((float) $r->qty_dispatched - (float) $r->shipped) > 0.0001)
            ->map(fn ($r) => [
                'key' => "dsp:{$r->id}", 'subject' => ['ORDER_LINE', (int) $r->id, "{$r->number} {$r->code}"], 'warehouse_id' => $r->warehouse_id,
                'measure' => 'Dispatched on the order against packed in shipped packages', 'unit' => 'units', 'actual' => (float) $r->qty_dispatched, 'baseline' => (float) $r->shipped,
                'basis' => 'The order line\'s dispatched quantity should equal what its shipped packages contain', 'evidence' => ['order' => $r->number],
                'impact' => abs((float) $r->qty_dispatched - (float) $r->shipped) * (float) $r->cost, 'confidence' => 'HIGH',
                'impact_basis' => 'Value of the difference, measured from the order and the packages.', 'action' => 'Reconcile the order with the packing record and the delivery note.',
            ])->values()->all();
    }

    private function detectEmergencyPurchases(LossRule $rule): array
    {
        return DB::table('purchase_request_items as l')->join('purchase_requests as r', 'r.id', '=', 'l.purchase_request_id')
            ->where('r.company_id', $this->companyId)->whereNotIn(DB::raw("COALESCE(r.urgency, 'normal')"), ['normal', 'low'])
            ->where('r.created_at', '>=', $this->since($rule))->groupBy('l.name')->havingRaw('COUNT(*) >= ?', [max((int) $rule->threshold, (int) $rule->min_count)])
            ->selectRaw('l.name, COUNT(*) AS n, SUM(l.line_total) AS value')->get()
            ->map(fn ($r) => [
                'key' => 'urgent:'.md5($r->name), 'subject' => ['PURCHASE_ITEM', null, $r->name], 'warehouse_id' => null,
                'measure' => 'Urgent purchase requests', 'unit' => 'requests', 'actual' => (int) $r->n, 'baseline' => (float) $rule->threshold,
                'basis' => "Rule: {$rule->threshold} or more urgent requests for the same item within {$rule->lookback_days} days", 'evidence' => ['value' => (float) $r->value],
                'impact' => 0, 'confidence' => 'LOW', 'impact_basis' => 'Not estimated: the premium paid for urgency is not recorded on the request.',
                'action' => 'Check the reorder point and lead time: urgent buying is usually a replenishment rule that is too tight.',
            ])->all();
    }

    private function detectOutOfHours(LossRule $rule): array
    {
        $out = [];
        $warehouses = DB::table('fin_warehouses')->where('company_id', $this->companyId)->whereNotNull('opening_time')->whereNotNull('closing_time')
            ->get(['id', 'code', 'opening_time', 'closing_time', 'timezone']);
        foreach ($warehouses as $w) {
            $tz = $w->timezone ?: 'Africa/Johannesburg';
            $rows = DB::table('wms_stock_adjustments')->where('warehouse_id', $w->id)->where('created_at', '>=', $this->since($rule))
                ->get(['number', 'created_at'])->filter(function ($a) use ($w, $tz) {
                    $local = \Illuminate\Support\Carbon::parse($a->created_at, 'UTC')->timezone($tz)->format('H:i:s');

                    return $local < $w->opening_time || $local > $w->closing_time;
                });
            if ($rows->count() >= max((int) $rule->threshold, (int) $rule->min_count)) {
                $out[] = ['key' => "hours:{$w->id}", 'subject' => ['WAREHOUSE', (int) $w->id, $w->code], 'warehouse_id' => $w->id,
                    'measure' => 'Stock corrections outside working hours', 'unit' => 'adjustments', 'actual' => $rows->count(), 'baseline' => (float) $rule->threshold,
                    'basis' => "Working hours {$w->opening_time}–{$w->closing_time} ({$tz}); rule: {$rule->threshold} or more corrections outside them within {$rule->lookback_days} days",
                    'evidence' => ['adjustments' => $rows->pluck('number')->values()->all()], 'impact' => 0, 'confidence' => 'LOW',
                    'impact_basis' => 'Not estimated: night shifts and late receipts are ordinary.', 'action' => 'Check who was on shift and whether the corrections were expected.'];
            }
        }

        return $out;
    }

    // ── Raising ──────────────────────────────────────────────────────────────────────────────

    private function raise(string $category, array $c): ?Finding
    {
        $rule = $this->rules[$category];
        $impact = round(max(0.0, (float) $c['impact']), 2);
        if ($impact < (float) $rule->min_impact && $category !== 'WH_HIGH_VALUE_ADJUSTMENT') {
            return null;
        }
        $key = "{$category}:{$c['key']}";
        $exists = Finding::query()->where('dedupe_key', $key)
            ->where(fn ($q) => $q->whereIn('status', Finding::OPEN)->orWhere('detected_at', '>=', now()->subDays($rule->lookback_days)))->exists();
        if ($exists) {
            return null;
        }

        return DB::transaction(function () use ($category, $c, $rule, $impact, $key) {
            [$type, $id, $label] = $c['subject'];
            $snapshot = $rule->only(['threshold', 'min_count', 'min_impact', 'lookback_days', 'approval_over']) + ['rule_updated_at' => $rule->updated_at?->toIso8601String()];
            $finding = Finding::create([
                'company_id' => $this->companyId, 'number' => $this->numbers->next($this->companyId, 'LOSS_FINDING'), 'category' => $category,
                'status' => Finding::DETECTED, 'subject_type' => $type, 'subject_id' => $id, 'subject_label' => mb_substr($label, 0, 250),
                'period_from' => $this->since($rule), 'period_to' => today()->toDateString(), 'measure' => $c['measure'], 'unit' => $c['unit'] ?? null,
                'actual_value' => round((float) $c['actual'], 4), 'baseline_value' => isset($c['baseline']) ? round((float) $c['baseline'], 4) : null,
                'baseline_basis' => $c['basis'], 'evidence' => $c['evidence'], 'estimated_impact' => $impact, 'confidence' => $c['confidence'],
                'impact_basis' => $c['impact_basis'], 'recommended_action' => $c['action'], 'due_date' => today()->addDays($rule->due_days)->toDateString(),
                'dedupe_key' => $key, 'detected_at' => now(), 'priority' => $this->priority($impact, $rule, $c['confidence']), 'rule_snapshot' => $snapshot,
                'origin' => 'RULE', 'warehouse_id' => $c['warehouse_id'] ?? null,
            ]);
            LossEvent::create(['company_id' => $this->companyId, 'finding_id' => $finding->id, 'action' => 'DETECTED', 'to_status' => Finding::DETECTED,
                'notes' => "{$c['measure']}: {$c['actual']} against ".($c['baseline'] ?? '—'), 'detail' => ['estimated_impact' => $impact, 'rule' => $snapshot], 'acted_at' => now()]);

            return $finding;
        });
    }

    private function priority(float $impact, LossRule $rule, string $confidence): string
    {
        return match (true) {
            $rule->approval_over !== null && $impact >= (float) $rule->approval_over => 'URGENT',
            $impact >= 1000 && $confidence !== 'LOW' => 'HIGH',
            $impact > 0 => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function since(LossRule $rule): string
    {
        return today()->subDays($rule->lookback_days)->toDateString();
    }
}
