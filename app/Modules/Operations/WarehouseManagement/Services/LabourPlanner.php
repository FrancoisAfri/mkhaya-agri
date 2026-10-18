<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use Illuminate\Support\Facades\DB;

/**
 * Labour (WMS-131): how long work takes here -- measured from the timings the floor records where
 * there are enough of them, set by the manager where there are not -- how much work there is, and
 * how many people with the right skills that needs against who is on the roster.
 */
class LabourPlanner
{
    /** activity => [minutes per task, minutes per unit] when nothing has been measured or set. */
    public const DEFAULTS = ['RECEIVE' => [10, 0.05], 'PUTAWAY' => [3, 0.02], 'REPLENISH' => [4, 0.02], 'PICK' => [1.5, 0.05],
        'PACK' => [3, 0.1], 'COUNT' => [1.5, 0.02], 'DISPATCH' => [5, 0]];

    /** activity on the board => skill on the roster */
    private const SKILL = ['RECEIVE' => 'RECEIVE', 'PUTAWAY' => 'PUTAWAY', 'REPLENISH' => 'REPLENISH', 'PICK' => 'PICK', 'PACK' => 'PACK', 'COUNT' => 'COUNT', 'DISPATCH' => 'DISPATCH'];

    public const MIN_SAMPLE = 10;

    /** Measure from recorded start and finish times; returns the durations (minutes) found, by activity. @return array<string, list<float>> */
    public function durations(int $warehouseId, int $days = 90): array
    {
        $since = now()->subDays($days);
        $minutes = fn ($q) => $q->whereNotNull('started_at')->whereNotNull('completed_at')->where('completed_at', '>=', $since)
            ->selectRaw('EXTRACT(EPOCH FROM (completed_at - started_at)) / 60.0 AS m, qty')->get()
            ->filter(fn ($r) => $r->m > 0 && $r->m < 240)->map(fn ($r) => ['m' => (float) $r->m, 'qty' => (float) $r->qty])->values()->all();

        $count = DB::table('wms_count_entries as e')->join('wms_count_lines as l', 'l.id', '=', 'e.count_line_id')->join('wms_counts as c', 'c.id', '=', 'l.count_id')
            ->where('c.warehouse_id', $warehouseId)->where('e.counted_at', '>=', $since)->orderBy('e.user_id')->orderBy('e.counted_at')->get(['e.user_id', 'e.counted_at', 'e.qty']);
        $gaps = [];
        for ($i = 1; $i < $count->count(); $i++) {
            if ($count[$i]->user_id === $count[$i - 1]->user_id) {
                $m = (strtotime($count[$i]->counted_at) - strtotime($count[$i - 1]->counted_at)) / 60;
                if ($m > 0 && $m < 30) {
                    $gaps[] = ['m' => $m, 'qty' => (float) $count[$i]->qty];
                }
            }
        }

        return [
            'PUTAWAY' => $minutes(DB::table('wms_putaway_tasks')->where('warehouse_id', $warehouseId)),
            'PICK' => $minutes(DB::table('wms_pick_tasks')->whereIn('wave_id', DB::table('wms_pick_waves')->where('warehouse_id', $warehouseId)->select('id'))),
            'COUNT' => $gaps,
        ];
    }

    /** Re-measure the standards that have enough timings; leave the rest as set. @return array<string, array> */
    public function remeasure(FinWarehouse $warehouse): array
    {
        $this->ensureDefaults($warehouse);
        foreach ($this->durations($warehouse->id) as $activity => $rows) {
            if (count($rows) < self::MIN_SAMPLE) {
                continue;
            }
            $per = collect($rows)->pluck('m')->sort()->values();
            DB::table('wms_labour_standards')->where('warehouse_id', $warehouse->id)->where('activity', $activity)->update([
                'minutes_per_task' => round((float) $per[intdiv($per->count() - 1, 2)], 2), 'minutes_per_unit' => 0, 'source' => 'MEASURED',
                'sample_size' => count($rows), 'updated_at' => now()]);
        }

        return $this->standards($warehouse);
    }

    /** @return array<string, array{minutes_per_task: float, minutes_per_unit: float, source: string, sample_size: int}> */
    public function standards(FinWarehouse $warehouse): array
    {
        $this->ensureDefaults($warehouse);

        return DB::table('wms_labour_standards')->where('warehouse_id', $warehouse->id)->get()->mapWithKeys(fn ($s) => [$s->activity => [
            'minutes_per_task' => (float) $s->minutes_per_task, 'minutes_per_unit' => (float) $s->minutes_per_unit, 'source' => $s->source, 'sample_size' => (int) $s->sample_size]])->all();
    }

    public function setStandard(FinWarehouse $warehouse, string $activity, float $perTask, float $perUnit): void
    {
        $this->ensureDefaults($warehouse);
        DB::table('wms_labour_standards')->where('warehouse_id', $warehouse->id)->where('activity', $activity)
            ->update(['minutes_per_task' => $perTask, 'minutes_per_unit' => $perUnit, 'source' => 'SET', 'sample_size' => 0, 'updated_at' => now()]);
    }

    /**
     * Workload against people: the open work on the board plus a normal day's new work, in hours,
     * against the people rostered here with each skill.
     *
     * @return array{activities: list<array>, shift_hours: float, utilisation: float, assumptions: list<string>}
     */
    public function plan(FinWarehouse $warehouse): array
    {
        $std = $this->standards($warehouse);
        $shift = (float) ($warehouse->policies['shift_hours'] ?? 8);
        $util = (float) ($warehouse->policies['utilisation'] ?? 0.85);
        $board = collect(app(TaskBoard::class)->queue($warehouse))->groupBy('type');
        $daily = $this->dailyArrivals($warehouse->id);
        $staff = WarehouseStaff::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->get();
        $out = [];
        foreach (self::DEFAULTS as $activity => $_) {
            $open = $board[$activity] ?? collect();
            $units = (float) $open->sum('qty');
            $s = $std[$activity];
            $openHours = ($open->count() * $s['minutes_per_task'] + $units * $s['minutes_per_unit']) / 60;
            $newHours = ($daily[$activity] ?? 0) * $s['minutes_per_task'] / 60;
            $hours = $openHours + $newHours;
            $people = $staff->filter(fn ($p) => in_array(self::SKILL[$activity], $p->activities ?? [], true))->count();
            $needed = $hours > 0 ? (int) ceil($hours / max(0.1, $shift * $util)) : 0;
            $out[] = ['activity' => $activity, 'open_tasks' => $open->count(), 'open_hours' => round($openHours, 1), 'expected_new' => round($daily[$activity] ?? 0, 1),
                'hours' => round($hours, 1), 'people_needed' => $needed, 'people_rostered' => $people, 'gap' => $people - $needed,
                'standard' => $s];
        }

        return ['activities' => $out, 'shift_hours' => $shift, 'utilisation' => $util, 'assumptions' => [
            "A shift is {$shift} hours, of which ".round($util * 100).'% is productive.',
            'New work is the daily average of the last 28 days.',
            'People count once for each skill they hold; somebody with two skills can cover either, not both at once.',
        ]];
    }

    /** Average new tasks a day over four weeks, by activity. @return array<string, float> */
    public function dailyArrivals(int $warehouseId, int $days = 28): array
    {
        $since = now()->subDays($days);
        $count = fn ($q) => $q->where('created_at', '>=', $since)->count() / $days;

        return [
            'PUTAWAY' => $count(DB::table('wms_putaway_tasks')->where('warehouse_id', $warehouseId)),
            'REPLENISH' => $count(DB::table('wms_replenishment_tasks')->where('warehouse_id', $warehouseId)),
            'PICK' => $count(DB::table('wms_pick_tasks')->whereIn('wave_id', DB::table('wms_pick_waves')->where('warehouse_id', $warehouseId)->select('id'))),
            'PACK' => $count(DB::table('wms_packages')->whereIn('order_id', DB::table('wms_orders')->where('warehouse_id', $warehouseId)->select('id'))),
            'DISPATCH' => $count(DB::table('wms_shipments')->where('warehouse_id', $warehouseId)),
            'RECEIVE' => $count(DB::table('goods_received_notes')->where('warehouse_id', $warehouseId)),
            'COUNT' => DB::table('wms_count_lines as l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')->where('c.warehouse_id', $warehouseId)
                ->where('l.created_at', '>=', $since)->count() / $days,
        ];
    }

    private function ensureDefaults(FinWarehouse $warehouse): void
    {
        foreach (self::DEFAULTS as $activity => [$task, $unit]) {
            DB::table('wms_labour_standards')->insertOrIgnore(['company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id, 'activity' => $activity,
                'minutes_per_task' => $task, 'minutes_per_unit' => $unit, 'source' => 'SET', 'sample_size' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
