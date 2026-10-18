<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use Illuminate\Support\Facades\DB;

/**
 * Picking advice (WMS-132): which allocated orders to pick together and in what order to walk,
 * from the layout (each bin's pick sequence), the orders' deadlines, and handling rules -- heavy
 * goods first so they sit at the bottom, hazardous goods in their own tote. Advice only: releasing
 * the waves is the pickers' supervisor's call on the picking screen.
 */
class PickAdvisor
{
    public const HEAVY_KG = 15.0;

    /** @return array{waves: list<array>, travel_now: float, travel_advised: float, constraints: list<string>} */
    public function advise(FinWarehouse $warehouse): array
    {
        $batchSize = (int) ($warehouse->policies['batch_size'] ?? 8);
        $lines = DB::table('wms_allocations as a')->join('wms_order_lines as ol', 'ol.id', '=', 'a.order_line_id')->join('wms_orders as o', 'o.id', '=', 'ol.order_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'a.bin_id')->leftJoin('mfg_items as m', 'm.stock_item_id', '=', 'ol.item_id')
            ->leftJoin('wms_item_profiles as p', 'p.stock_item_id', '=', 'ol.item_id')->join('fin_stock_items as i', 'i.id', '=', 'ol.item_id')
            ->where('o.warehouse_id', $warehouse->id)->whereIn('o.status', ['ALLOCATED', 'PARTIALLY_ALLOCATED'])->where('a.status', 'ACTIVE')
            ->whereNotExists(fn ($q) => $q->from('wms_pick_tasks as t')->whereColumn('t.allocation_id', 'a.id')->whereIn('t.status', ['OPEN', 'IN_PROGRESS']))
            ->get(['o.id', 'o.uuid', 'o.number', 'o.sla_due_at', 'b.code as bin', DB::raw('COALESCE(b.pick_sequence, 999999) AS seq'), 'i.code as item',
                'm.weight_kg', 'p.hazard_class', 'a.qty']);
        if ($lines->isEmpty()) {
            return ['waves' => [], 'travel_now' => 0, 'travel_advised' => 0, 'constraints' => []];
        }
        $orders = $lines->groupBy('id');
        // Walking each order on its own: the span of its path, end to end and back.
        $span = fn ($rows) => $rows->isEmpty() ? 0 : (float) ($rows->max('seq') - $rows->min('seq'));
        $travelNow = $orders->sum(fn ($rows) => $span($rows));

        // Urgent orders go alone and first; the rest are grouped by where in the warehouse they start.
        $urgent = $orders->filter(fn ($rows) => $rows->first()->sla_due_at && now()->addHours(2)->gt($rows->first()->sla_due_at));
        $rest = $orders->except($urgent->keys())->sortBy(fn ($rows) => $rows->min('seq'));
        $waves = [];
        foreach ($urgent as $rows) {
            $waves[] = $this->wave('DISCRETE', collect([$rows]), 'Due within two hours: picked on its own, first.');
        }
        foreach ($rest->chunk($batchSize) as $chunk) {
            $strategy = $chunk->count() > 1 ? 'BATCH' : 'DISCRETE';
            $waves[] = $this->wave($strategy, $chunk->values(), $chunk->count() > 1 ? "{$chunk->count()} orders starting in the same part of the warehouse, walked once." : 'Single order.');
        }
        $travelAdvised = collect($waves)->sum('travel');
        $constraints = [];
        if ($lines->contains(fn ($l) => (float) $l->weight_kg >= self::HEAVY_KG)) {
            $constraints[] = 'Heavy items (from '.self::HEAVY_KG.' kg) are picked first in each wave so they travel at the bottom.';
        }
        if ($lines->contains(fn ($l) => $l->hazard_class)) {
            $constraints[] = 'Hazardous items are flagged to go in a separate tote.';
        }

        return ['waves' => $waves, 'travel_now' => $travelNow, 'travel_advised' => $travelAdvised, 'constraints' => $constraints,
            'saving_pct' => $travelNow > 0 ? round((1 - $travelAdvised / $travelNow) * 100, 1) : null];
    }

    private function wave(string $strategy, $orders, string $why): array
    {
        $rows = $orders->flatten(1);
        $sequence = $rows->sortBy([fn ($a, $b) => ((float) $b->weight_kg >= self::HEAVY_KG) <=> ((float) $a->weight_kg >= self::HEAVY_KG), fn ($a, $b) => $a->seq <=> $b->seq])
            ->map(fn ($r) => ['bin' => $r->bin, 'item' => $r->item, 'qty' => (float) $r->qty, 'order' => $r->number,
                'heavy' => (float) $r->weight_kg >= self::HEAVY_KG, 'hazardous' => (bool) $r->hazard_class])->values()->all();

        return ['strategy' => $strategy, 'orders' => $orders->map(fn ($r) => ['id' => $r->first()->uuid, 'number' => $r->first()->number])->values()->all(),
            'why' => $why, 'stops' => count(array_unique(array_column($sequence, 'bin'))), 'travel' => (float) ($rows->max('seq') - $rows->min('seq')), 'sequence' => $sequence];
    }
}
