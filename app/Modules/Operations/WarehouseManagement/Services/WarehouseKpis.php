<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product KPIs (§33), each defined precisely -- numerator, denominator, exclusions and window -- so
 * a figure cannot be improved by quietly changing what is counted. A KPI that cannot be measured
 * from what the warehouse records is reported as not available, with why, never estimated.
 *
 * Definitions are versioned: a snapshot keeps the version it was computed under, so a changed
 * definition is never compared with an old figure as if they were alike.
 */
class WarehouseKpis
{
    use ScopesRawQueries;

    public const VERSION = 1;

    /** key => [name, unit, higher is better, definition, exclusions] */
    public const DEFINITIONS = [
        'inventory_accuracy' => ['Inventory accuracy', '%', true,
            'Count lines whose first count matched the system quantity (within the count tolerance) ÷ count lines counted, for counts posted in the window.',
            'Recounts are not counted again; lines of cancelled counts are excluded.'],
        'picking_accuracy' => ['Picking accuracy', '%', true,
            'Pick tasks completed with no short quantity and no wrong scan ÷ pick tasks completed in the window.',
            'Cancelled pick tasks are excluded.'],
        'order_cycle_time' => ['Order cycle time', 'hours', false,
            'Median hours from an order\'s release to its dispatch, for orders dispatched in the window.',
            'Cancelled orders and orders never released are excluded.'],
        'receiving_throughput' => ['Receiving throughput', 'lines per labour hour', true,
            'Accepted receipt lines posted in the window ÷ labour hours of receiving staff. Labour hours are the hours worked (Attendance) by people assigned to the RECEIVE activity, shared equally across each person\'s assigned activities.',
            'Receipts without an order awaiting approval are excluded; without Attendance records the KPI is not available.'],
        'on_time_dispatch' => ['On-time dispatch', '%', true,
            'Orders dispatched by their dispatch deadline ÷ orders with a deadline dispatched in the window.',
            'Orders without a deadline are excluded from both sides.'],
        'stockout_rate' => ['Stock-out rate', '%', false,
            'Order lines released in the window that went short for lack of stock ÷ order lines released in the window.',
            'Cancelled lines are excluded; lines filled by an approved substitute count as filled.'],
        'write_off_rate' => ['Write-off rate', '% of stock value', false,
            'Value of confirmed write-offs posted in the window ÷ inventory value at the end of the window.',
            'Stock held for clients (not valued) is excluded; suspected losses not yet confirmed are excluded.'],
        'forecast_accuracy' => ['Forecast accuracy', '% (100 − WAPE)', true,
            '100 − weighted absolute percentage error over forecast periods that have ended, comparing forecast (or its override) with actual outbound demand.',
            'Items with insufficient history (no forecast) are excluded.'],
        'supplier_delivery_accuracy' => ['Supplier delivery accuracy', '%', true,
            'Receipts against an order, received on or before the order\'s expected date with no quantity discrepancy ÷ receipts against an order in the window.',
            'Receipts without an order are excluded.'],
        'investigation_resolution' => ['Investigation resolution', 'days (median)', false,
            'Median days from detection to closure of stock-risk investigations closed in the window.',
            'Findings outside warehouses are excluded.'],
        'replenishment_effectiveness' => ['Pick-face shortages', 'per 1,000 picks', false,
            'Pick tasks that went short ÷ pick tasks completed, × 1,000, in the window -- each a pick face that was not replenished in time.',
            'Cancelled pick tasks are excluded.'],
        'carrying_exposure' => ['Inventory carrying exposure', 'value', false,
            'Value of stock classed obsolete, non-moving or slow under the excess-and-obsolete policy, at the time of calculation.',
            'Stock held for clients is excluded.'],
    ];

    /** @return array<string, array{value: ?float, numerator: ?float, denominator: ?float, note: ?string}> */
    public function compute(Carbon $from, Carbon $to): array
    {
        $cid = $this->tenantId();
        $t = fn (string $table, string $alias = '') => DB::table($table.($alias ? " as {$alias}" : ''))->when($cid, fn ($q, $c) => $q->where(($alias ?: $table).'.company_id', $c));
        $range = [$from, $to];
        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1) : null;
        $out = [];

        $lines = $t('wms_count_lines', 'l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')->where('c.status', 'POSTED')->whereBetween('c.updated_at', $range)
            ->where('l.round', 1)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN l.status = 'MATCHED' OR (l.status IN ('ACCEPTED','DISMISSED','ADJUSTED') AND ABS(COALESCE(l.variance_qty, 0)) < 0.0001) THEN 1 ELSE 0 END) AS ok")->first();
        $out['inventory_accuracy'] = $this->r($pct((int) $lines->ok, (int) $lines->n), (int) $lines->ok, (int) $lines->n);

        $picks = $t('wms_pick_tasks')->whereIn('status', ['PICKED', 'SHORT'])->whereBetween('completed_at', $range)
            ->selectRaw('COUNT(*) AS n, SUM(CASE WHEN COALESCE(short_qty, 0) = 0 AND COALESCE(scan_errors, 0) = 0 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN COALESCE(short_qty, 0) > 0 THEN 1 ELSE 0 END) AS short')->first();
        $out['picking_accuracy'] = $this->r($pct((int) $picks->ok, (int) $picks->n), (int) $picks->ok, (int) $picks->n);
        $out['replenishment_effectiveness'] = $this->r((int) $picks->n ? round((int) $picks->short / (int) $picks->n * 1000, 1) : null, (int) $picks->short, (int) $picks->n);

        $cycle = $t('wms_orders')->whereBetween('dispatched_at', $range)->whereNotNull('released_at')
            ->selectRaw('COUNT(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (dispatched_at - released_at)) / 3600) AS p50')->first();
        $out['order_cycle_time'] = $this->r($cycle->p50 !== null ? round((float) $cycle->p50, 1) : null, null, (int) $cycle->n);

        $onTime = $t('wms_orders')->whereBetween('dispatched_at', $range)->whereNotNull('sla_due_at')
            ->selectRaw('COUNT(*) AS n, SUM(CASE WHEN dispatched_at <= sla_due_at THEN 1 ELSE 0 END) AS ok')->first();
        $out['on_time_dispatch'] = $this->r($pct((int) $onTime->ok, (int) $onTime->n), (int) $onTime->ok, (int) $onTime->n);

        $released = $t('wms_order_lines', 'l')->join('wms_orders as o', 'o.id', '=', 'l.order_id')->whereBetween('o.released_at', $range)
            ->whereRaw('l.qty > COALESCE(l.qty_cancelled, 0)')
            ->selectRaw('COUNT(*) AS n, SUM(CASE WHEN COALESCE(l.qty_short, 0) > 0 AND NOT EXISTS (SELECT 1 FROM wms_order_lines s WHERE s.substitute_of_line_id = l.id) THEN 1 ELSE 0 END) AS short')->first();
        $out['stockout_rate'] = $this->r($pct((int) $released->short, (int) $released->n), (int) $released->short, (int) $released->n);

        $value = (float) app(CostReports::class)->report(null, $from->toDateString(), $to->toDateString())['total_value'];
        $writeOffs = (float) app(LossControl::class)->register(null, $from->toDateString(), $to->toDateString())['confirmed']['total'];
        $out['write_off_rate'] = $this->r($value > 0 ? round($writeOffs / $value * 100, 2) : null, round($writeOffs, 2), round($value, 2));

        $acc = app(DemandForecaster::class)->accuracy()['overall'] ?? [];
        $out['forecast_accuracy'] = isset($acc['wape']) && $acc['wape'] !== null
            ? $this->r(round(max(0, 100 - (float) $acc['wape']), 1), null, (float) ($acc['periods'] ?? 0))
            : $this->r(null, null, null, 'No forecast period has ended yet, or there is too little history to forecast.');

        $grns = $t('goods_received_notes', 'g')->join('purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')->whereBetween('g.received_at', $range)
            ->selectRaw("COUNT(*) AS n, SUM(CASE WHEN g.received_at::date <= po.expected_delivery AND NOT EXISTS (SELECT 1 FROM wms_receipt_discrepancies d WHERE d.grn_id = g.id AND d.kind IN ('UNDER','OVER','MISSING','WRONG_ITEM') AND d.within_tolerance = false) THEN 1 ELSE 0 END) AS ok")->first();
        $out['supplier_delivery_accuracy'] = $this->r($pct((int) $grns->ok, (int) $grns->n), (int) $grns->ok, (int) $grns->n);

        $closed = $t('mfg_loss_findings')->whereNotNull('warehouse_id')->whereBetween('closed_at', $range)
            ->selectRaw('COUNT(*) AS n, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (closed_at - detected_at)) / 86400) AS p50')->first();
        $out['investigation_resolution'] = $this->r($closed->p50 !== null ? round((float) $closed->p50, 1) : null, null, (int) $closed->n);

        $out['receiving_throughput'] = $this->receiving($cid, $from, $to);
        $out['carrying_exposure'] = $this->r(round(collect(app(ExcessObsolete::class)->analyse()['totals'])->sum('value'), 2), null, null);

        return $out;
    }

    private function receiving(?int $cid, Carbon $from, Carbon $to): array
    {
        $accepted = (int) DB::table('goods_received_items as i')->join('goods_received_notes as g', 'g.id', '=', 'i.goods_received_note_id')
            ->when($cid, fn ($q, $c) => $q->where('g.company_id', $c))->whereBetween('g.received_at', [$from, $to])->whereNotNull('g.purchase_order_id')
            ->where('i.accepted_qty', '>', 0)->count();
        $staff = DB::table('wms_warehouse_staff')->when($cid, fn ($q, $c) => $q->where('company_id', $c))->where('is_active', true)->get(['user_id', 'activities'])
            ->map(fn ($s) => ['user_id' => (int) $s->user_id, 'acts' => json_decode((string) $s->activities, true) ?: []])
            ->filter(fn ($s) => in_array('RECEIVE', $s['acts'], true));
        if ($staff->isEmpty()) {
            return $this->r(null, $accepted, null, 'Nobody is assigned the RECEIVE activity, so receiving labour hours are unknown.');
        }
        $hours = 0.0;
        foreach ($staff as $s) {
            $worked = (float) DB::table('attendance_records as a')->join('hr_people as p', 'p.id', '=', 'a.employee_id')->where('p.user_id', $s['user_id'])
                ->whereBetween('a.date', [$from->toDateString(), $to->toDateString()])->whereNull('a.deleted_at')->sum('a.hours_worked');
            $hours += $worked / max(1, count($s['acts']));
        }
        if ($hours <= 0) {
            return $this->r(null, $accepted, 0, 'Receiving staff have no Attendance hours in the window; clock-ins are needed to measure this.');
        }

        return $this->r(round($accepted / $hours, 2), $accepted, round($hours, 1));
    }

    private function r(?float $value, int|float|null $numerator, int|float|null $denominator, ?string $note = null): array
    {
        return ['value' => $value, 'numerator' => $numerator, 'denominator' => $denominator,
            'note' => $note ?? ($value === null ? 'Nothing in the window to measure.' : null)];
    }

    /** Store the month's figures; a snapshot is never rewritten, only added to. */
    public function snapshot(int $companyId, string $period): int
    {
        $from = Carbon::parse($period.'-01')->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $n = 0;
        foreach ($this->compute($from, $to) as $key => $r) {
            $n += DB::table('wms_kpi_snapshots')->insertOrIgnore(['company_id' => $companyId, 'period' => $period, 'kpi' => $key, 'definition_version' => self::VERSION,
                'value' => $r['value'], 'numerator' => $r['numerator'], 'denominator' => $r['denominator'], 'note' => $r['note'], 'created_at' => now()]);
        }

        return $n;
    }
}
