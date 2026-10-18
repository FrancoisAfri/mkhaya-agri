<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use Illuminate\Support\Facades\DB;

/**
 * How the warehouse is performing (WMS-134), as a process: work done, accuracy, throughput, cycle
 * time, rework and where work queues up. There is deliberately no league table of people -- task
 * difficulty, equipment, safety and data quality differ, and a ranking that ignores them is unfair --
 * so figures are by activity, by stage and by zone.
 */
class Productivity
{
    public function report(FinWarehouse $warehouse, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $days = max(1, now()->parse($from)->diffInDays(now()->parse($to)) + 1);
        $wid = $warehouse->id;
        $waves = DB::table('wms_pick_waves')->where('warehouse_id', $wid)->select('id');

        $picks = DB::table('wms_pick_tasks')->whereIn('wave_id', $waves)->whereBetween('completed_at', $range)
            ->selectRaw('COUNT(*) AS lines, SUM(CASE WHEN short_qty > 0 THEN 1 ELSE 0 END) AS short, SUM(COALESCE(scan_errors, 0)) AS scan_errors, SUM(picked_qty) AS units')->first();
        $putaway = DB::table('wms_putaway_tasks')->where('warehouse_id', $wid)->whereBetween('completed_at', $range)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed")->first();
        $receipts = DB::table('goods_received_items as gi')->join('goods_received_notes as g', 'g.id', '=', 'gi.goods_received_note_id')->where('g.warehouse_id', $wid)
            ->whereBetween('g.created_at', $range)->selectRaw('COUNT(*) AS lines, COUNT(DISTINCT g.id) AS notes')->first();
        $cycle = DB::table('wms_orders')->where('warehouse_id', $wid)->whereBetween('dispatched_at', $range)->whereNotNull('released_at')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (dispatched_at - released_at)) / 3600) AS p50,
                         percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (dispatched_at - released_at)) / 3600) AS p90,
                         COUNT(*) AS n, SUM(CASE WHEN sla_due_at IS NULL OR dispatched_at <= sla_due_at THEN 1 ELSE 0 END) AS on_time')->first();
        $recounts = DB::table('wms_count_lines as l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')->where('c.warehouse_id', $wid)
            ->whereBetween('l.updated_at', $range)->selectRaw('COUNT(*) AS lines, SUM(CASE WHEN l.round > 1 THEN 1 ELSE 0 END) AS recounted')->first();
        $reopened = DB::table('wms_packages')->whereIn('order_id', DB::table('wms_orders')->where('warehouse_id', $wid)->select('id'))
            ->whereBetween('updated_at', $range)->where('status', 'OPEN')->whereNotNull('closed_at')->count();
        $timed = DB::table('wms_pick_tasks')->whereIn('wave_id', $waves)->whereBetween('completed_at', $range)->whereNotNull('started_at')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (completed_at - started_at)) / 60) AS m')->value('m');

        $board = collect(app(TaskBoard::class)->queue($warehouse));
        $queues = $board->groupBy('type')->map(fn ($g, $type) => ['stage' => $type, 'waiting' => $g->count(), 'overdue' => $g->where('overdue', true)->count(),
            'blocked' => $g->where('state', 'BLOCKED')->count(),
            'oldest_due' => $g->pluck('due_at')->filter()->sort()->first()])->sortByDesc('overdue')->values()->all();
        $bottleneck = collect($queues)->sortByDesc(fn ($q) => $q['overdue'] * 10 + $q['blocked'] * 5 + $q['waiting'])->first();

        return [
            'period_days' => $days,
            'work' => ['pick_lines' => (int) $picks->lines, 'units_picked' => (float) $picks->units, 'putaways' => (int) $putaway->n,
                'receipt_lines' => (int) $receipts->lines, 'receipts' => (int) $receipts->notes, 'orders_dispatched' => (int) $cycle->n],
            'accuracy' => ['picking' => $picks->lines ? round((1 - ((int) $picks->short) / (int) $picks->lines) * 100, 1) : null,
                'scan_errors' => (int) $picks->scan_errors, 'failed_putaways' => (int) $putaway->failed],
            'throughput' => ['receipt_lines_per_day' => round((int) $receipts->lines / $days, 1), 'pick_lines_per_day' => round((int) $picks->lines / $days, 1),
                'median_minutes_per_pick' => $timed !== null ? round((float) $timed, 1) : null],
            'cycle' => ['median_hours' => $cycle->p50 !== null ? round((float) $cycle->p50, 1) : null, 'p90_hours' => $cycle->p90 !== null ? round((float) $cycle->p90, 1) : null,
                'on_time' => $cycle->n ? round((int) $cycle->on_time / (int) $cycle->n * 100, 1) : null],
            'rework' => ['recounted_lines' => (int) $recounts->recounted, 'count_lines' => (int) $recounts->lines, 'reopened_packages' => $reopened,
                'short_picks' => (int) $picks->short],
            'queues' => $queues,
            'bottleneck' => $bottleneck && ($bottleneck['overdue'] > 0 || $bottleneck['blocked'] > 0) ? $bottleneck : null,
            'not_measured' => ['Travel time is not measured: the floor records tasks, not distance walked.',
                'Receiving labour hours are not recorded, so receiving throughput is per day, not per labour hour.'],
        ];
    }
}
