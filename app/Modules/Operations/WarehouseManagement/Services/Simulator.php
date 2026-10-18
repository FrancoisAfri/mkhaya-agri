<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Simulation (WMS-135): what is likely to happen before anybody changes anything.
 *
 * Every simulation samples from this warehouse's own history -- how long tasks really took, how much
 * work really arrived each day, which bins orders really drew from -- many times over, and reports
 * the spread (10th, 50th and 90th percentile), not a single number, with the assumptions it made.
 * The random seed is kept, so a result can be reproduced exactly.
 *
 *  - STAFFING / DEMAND: will the work get done with this many people, at this level of demand?
 *  - LAYOUT: how far will pickers walk with products where a slotting proposal would put them?
 *  - REPLENISHMENT: with this minimum and maximum, how often does a product run out?
 */
class Simulator
{
    public const TRIALS = 500;

    public function __construct(private readonly LabourPlanner $labour) {}

    /**
     * @param array{staff: array<string,int>, demand_pct?: int, shift_hours?: float, trials?: int, seed?: int} $in
     */
    public function staffing(FinWarehouse $warehouse, array $in, User $by): array
    {
        $seed = $this->seed(isset($in['seed']) ? (int) $in['seed'] : null);
        $trials = min((int) ($in['trials'] ?? self::TRIALS), 5000);
        $scale = 1 + ((int) ($in['demand_pct'] ?? 0)) / 100;
        $shift = (float) ($in['shift_hours'] ?? $warehouse->policies['shift_hours'] ?? 8);
        $util = (float) ($warehouse->policies['utilisation'] ?? 0.85);
        $durations = $this->labour->durations($warehouse->id);
        $standards = $this->labour->standards($warehouse);
        $daily = $this->dailyHistory($warehouse->id);
        $results = [];
        $assumptions = ["{$trials} simulated days; demand ".($scale === 1.0 ? 'as recorded' : 'scaled by '.round(($scale - 1) * 100).'%').'.',
            "Each person works {$shift} hours at ".round($util * 100).'% productive time.'];

        foreach (LabourPlanner::DEFAULTS as $activity => $_) {
            $people = (int) ($in['staff'][$activity] ?? 0);
            $history = $daily[$activity] ?? [];
            $measured = array_column($durations[$activity] ?? [], 'm');
            if ($history === [] && $people === 0) {
                continue;
            }
            $hours = [];
            for ($t = 0; $t < $trials; $t++) {
                $tasks = (int) round(($history ? $history[mt_rand(0, count($history) - 1)] : 0) * $scale);
                $minutes = 0.0;
                for ($k = 0; $k < $tasks; $k++) {
                    // A measured time where there are enough; otherwise the standard with ±25% spread.
                    $minutes += count($measured) >= LabourPlanner::MIN_SAMPLE ? $measured[mt_rand(0, count($measured) - 1)]
                        : $standards[$activity]['minutes_per_task'] * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
                }
                $hours[] = $minutes / 60;
            }
            sort($hours);
            $capacity = $people * $shift * $util;
            $late = count(array_filter($hours, fn ($h) => $h > $capacity));
            $results[$activity] = ['people' => $people, 'capacity_hours' => round($capacity, 1), 'work_hours' => $this->percentiles($hours),
                'chance_work_left_over' => round($late / max(1, $trials) * 100, 1),
                'people_for_95pct' => (int) ceil($hours[(int) floor(0.95 * (count($hours) - 1))] / max(0.1, $shift * $util)),
                'basis' => count($measured) >= LabourPlanner::MIN_SAMPLE ? 'measured task times ('.count($measured).')' : 'standard time ±25% (too few measured)',
                'history_days' => count($history)];
        }

        return $this->save($warehouse, ($in['demand_pct'] ?? 0) ? 'DEMAND' : 'STAFFING', $in, $results, $assumptions, $trials, $seed, $by);
    }

    /**
     * Walking distance per order now, and with products moved to new pick sequences.
     *
     * @param array<int,int> $mapping item id => proposed pick sequence
     */
    public function layout(FinWarehouse $warehouse, array $mapping, User $by, int $trials = self::TRIALS, ?int $seed = null): array
    {
        $seed = $this->seed($seed);
        $metres = (float) ($warehouse->policies['metres_per_slot'] ?? 1.5);
        $orders = DB::table('wms_pick_tasks as t')->join('wms_pick_waves as w', 'w.id', '=', 't.wave_id')->join('fin_stock_bins as b', 'b.id', '=', 't.bin_id')
            ->where('w.warehouse_id', $warehouse->id)->where('t.created_at', '>=', now()->subDays(90))->whereNotNull('b.pick_sequence')
            ->get(['t.order_id', 't.item_id', 'b.pick_sequence'])->groupBy('order_id')->values();
        if ($orders->isEmpty()) {
            throw new RuntimeException('There are no picks in the last 90 days to simulate walking with.');
        }
        $walk = fn ($seqs) => count($seqs) ? (max($seqs) * 2) * $metres : 0.0; // out to the furthest stop and back
        $now = $proposed = [];
        for ($t = 0; $t < $trials; $t++) {
            $o = $orders[mt_rand(0, $orders->count() - 1)];
            $now[] = $walk($o->pluck('pick_sequence')->map('intval')->all());
            $proposed[] = $walk($o->map(fn ($l) => (int) ($mapping[$l->item_id] ?? $l->pick_sequence))->all());
        }
        sort($now);
        sort($proposed);
        $results = ['metres_per_order_now' => $this->percentiles($now), 'metres_per_order_proposed' => $this->percentiles($proposed),
            'saving_pct' => array_sum($now) > 0 ? round((1 - array_sum($proposed) / array_sum($now)) * 100, 1) : 0.0, 'orders_sampled_from' => $orders->count(),
            'moves' => count($mapping)];
        $assumptions = ["Distance is out to the furthest stop and back, {$metres} m per pick-sequence step; aisles and congestion are not modelled.",
            'Orders are drawn from the last 90 days of picking.', 'Products not being moved stay where they are.'];

        return $this->save($warehouse, 'LAYOUT', ['mapping' => $mapping], $results, $assumptions, $trials, $seed, $by);
    }

    /** @param array{item_id:int, min:float, max:float, lead_days:int, horizon_days?:int, trials?:int, seed?:int} $in */
    public function replenishment(FinWarehouse $warehouse, array $in, User $by): array
    {
        $seed = $this->seed(isset($in['seed']) ? (int) $in['seed'] : null);
        $item = FinStockItem::query()->findOrFail($in['item_id']);
        $trials = min((int) ($in['trials'] ?? self::TRIALS), 5000);
        $horizon = (int) ($in['horizon_days'] ?? 60);
        $min = (float) $in['min'];
        $max = (float) $in['max'];
        $lead = max(0, (int) $in['lead_days']);
        if ($max <= $min) {
            throw new RuntimeException('The maximum must be above the minimum.');
        }
        $daily = $this->dailyDemand($item->id, $warehouse->id);
        if (array_sum($daily) <= 0) {
            throw new RuntimeException("{$item->code} has no recorded demand in the last 90 days to simulate with.");
        }
        $start = (float) DB::table('fin_stock_balances')->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->value('qty');
        $stockoutDays = $orders = $avgStock = [];
        for ($t = 0; $t < $trials; $t++) {
            $stock = $start;
            $pipeline = [];
            $out = 0;
            $placed = 0;
            $sum = 0.0;
            for ($d = 0; $d < $horizon; $d++) {
                $stock += $pipeline[$d] ?? 0;
                $want = $daily[mt_rand(0, count($daily) - 1)];
                if ($want > $stock) {
                    $out++;
                }
                $stock = max(0.0, $stock - $want);
                $onOrder = array_sum(array_filter($pipeline, fn ($q, $day) => $day > $d, ARRAY_FILTER_USE_BOTH));
                if ($stock + $onOrder <= $min) {
                    $pipeline[$d + max(1, $lead)] = ($pipeline[$d + max(1, $lead)] ?? 0) + ($max - $stock - $onOrder);
                    $placed++;
                }
                $sum += $stock;
            }
            $stockoutDays[] = $out;
            $orders[] = $placed;
            $avgStock[] = $sum / $horizon;
        }
        sort($stockoutDays);
        sort($orders);
        sort($avgStock);
        $results = ['stockout_days' => $this->percentiles($stockoutDays), 'chance_of_any_stockout' => round(count(array_filter($stockoutDays)) / $trials * 100, 1),
            'orders_placed' => $this->percentiles($orders), 'average_stock' => $this->percentiles($avgStock), 'unit_cost' => (float) ($item->avg_cost ?: $item->standard_cost),
            'average_stock_value' => round($this->percentiles($avgStock)['p50'] * (float) ($item->avg_cost ?: $item->standard_cost), 2)];
        $assumptions = ["Daily demand is drawn from {$item->code}'s last 90 days in this warehouse, quiet days included.",
            "Orders arrive in exactly {$lead} days and are always delivered in full.", "Starting stock {$start}; {$horizon} days simulated {$trials} times."];

        return $this->save($warehouse, 'REPLENISHMENT', $in + ['item' => $item->code], $results, $assumptions, $trials, $seed, $by);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function seed(?int $given = null): int
    {
        $seed = $given ?: random_int(1, PHP_INT_MAX >> 1);
        mt_srand($seed);

        return $seed;
    }

    /** @param list<float> $sorted */
    private function percentiles(array $sorted): array
    {
        $n = count($sorted);
        $at = fn (float $p) => $n ? round((float) $sorted[(int) floor($p * ($n - 1))], 2) : 0.0;

        return ['p10' => $at(0.1), 'p50' => $at(0.5), 'p90' => $at(0.9)];
    }

    /** Tasks created per day for the last 28 days, by activity -- quiet days included. @return array<string, list<int>> */
    private function dailyHistory(int $warehouseId): array
    {
        $since = now()->subDays(28)->startOfDay();
        $days = collect(range(0, 27))->map(fn ($i) => $since->copy()->addDays($i)->toDateString());
        $per = fn ($q, string $col = 'created_at') => $q->where($col, '>=', $since)->selectRaw("DATE({$col}) AS d, COUNT(*) AS n")->groupByRaw("DATE({$col})")->pluck('n', 'd');
        $series = [
            'PUTAWAY' => $per(DB::table('wms_putaway_tasks')->where('warehouse_id', $warehouseId)),
            'REPLENISH' => $per(DB::table('wms_replenishment_tasks')->where('warehouse_id', $warehouseId)),
            'PICK' => $per(DB::table('wms_pick_tasks')->whereIn('wave_id', DB::table('wms_pick_waves')->where('warehouse_id', $warehouseId)->select('id'))),
            'DISPATCH' => $per(DB::table('wms_shipments')->where('warehouse_id', $warehouseId)),
            'RECEIVE' => $per(DB::table('goods_received_notes')->where('warehouse_id', $warehouseId)),
        ];

        return collect($series)->map(fn ($s) => $s->isEmpty() ? [] : $days->map(fn ($d) => (int) ($s[$d] ?? 0))->all())->all();
    }

    /** @return list<float> daily quantity out, last 90 days, zero days included */
    private function dailyDemand(int $itemId, int $warehouseId): array
    {
        $since = now()->subDays(90)->startOfDay();
        $by = DB::table('fin_stock_movements')->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->where('qty', '<', 0)
            ->whereIn('type', ['dispatch', 'sale', 'issue', 'mfg_issue'])->whereNull('reversal_of_id')->where('movement_date', '>=', $since->toDateString())
            ->groupBy('movement_date')->selectRaw('movement_date AS d, SUM(ABS(qty)) AS q')->pluck('q', 'd');

        return collect(range(0, 89))->map(fn ($i) => (float) ($by[$since->copy()->addDays($i)->toDateString()] ?? 0))->all();
    }

    private function save(FinWarehouse $warehouse, string $kind, array $inputs, array $results, array $assumptions, int $trials, int $seed, User $by): array
    {
        $uuid = (string) Str::uuid();
        DB::table('wms_simulations')->insert(['uuid' => $uuid, 'company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id, 'kind' => $kind,
            'inputs' => json_encode($inputs), 'results' => json_encode($results + ['assumptions' => $assumptions]), 'trials' => $trials, 'seed' => $seed,
            'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);

        return ['uuid' => $uuid, 'kind' => $kind, 'results' => $results, 'assumptions' => $assumptions, 'trials' => $trials, 'seed' => $seed];
    }
}
