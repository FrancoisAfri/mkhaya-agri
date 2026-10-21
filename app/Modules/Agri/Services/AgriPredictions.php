<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\AgriPrediction;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\HarvestForecast;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\MachineFault;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Carbon;

/**
 * Phase 2 §10.2: predictions that are checked.
 *
 * Each day the farm's predictions are written down as they stand — expected yield, harvest
 * readiness, stock running out, machine breakdown risk — with their basis, a confidence and the
 * date of the data behind them. When the outcome is known each is scored. evaluate() reports, per
 * model, how far off it has been on this farm's own history, so a manager can see how much weight a
 * prediction deserves before acting on it.
 */
class AgriPredictions
{
    public const CARDS = [
        'yield' => ['name' => 'Yield forecast', 'unit' => '% error',
            'limits' => 'Assumes the season continues as recorded; weather after the forecast date, pests and harvest losses are not foreseen. Uses the plan\'s yield unit throughout.'],
        'harvest_ready' => ['name' => 'Harvest readiness', 'unit' => 'days early/late',
            'limits' => 'Based on the forecast ready date or the planned harvest date; field access and weather can move it.'],
        'stock_out' => ['name' => 'Inventory depletion', 'unit' => 'hit rate',
            'limits' => 'Projects the last 90 days\' use forward; a change in work plans or a delivery changes it.'],
        'machine_breakdown' => ['name' => 'Equipment maintenance risk', 'unit' => 'breakdowns by risk band',
            'limits' => 'A ranking from service, fault and check records, not a failure date. Breakdowns are rare, so small numbers make its record noisy.'],
    ];

    public function __construct(private readonly MachineRisk $risk, private readonly FarmInventory $inventory, private readonly StockLedger $stock) {}

    /** Write today's predictions down. Once a day per subject; a second run the same day changes nothing. */
    public function snapshot(int $companyId): int
    {
        $n = 0;
        $today = today()->toDateString();
        $keep = function (array $p) use ($companyId, $today, &$n) {
            $row = AgriPrediction::query()->firstOrCreate(['company_id' => $companyId, 'model' => $p['model'], 'subject_type' => $p['subject_type'], 'subject_id' => $p['subject_id'],
                'predicted_on' => $today], $p + ['data_as_of' => $today]);
            $n += $row->wasRecentlyCreated ? 1 : 0;
        };

        foreach (CropPlan::query()->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->get() as $plan) {
            $f = HarvestForecast::query()->where('crop_plan_id', $plan->id)->latest('id')->first();
            $expected = $f?->quantity ?? ($plan->expected_yield !== null ? (float) $plan->expected_yield * $plan->hectares() : null);
            if ($expected !== null) {
                $keep(['farm_id' => $plan->farm_id, 'model' => 'yield', 'subject_type' => 'crop_plan', 'subject_id' => $plan->id, 'predicted_value' => round((float) $expected, 3),
                    'unit' => $plan->yield_unit, 'target_date' => $plan->planned_harvest_on, 'confidence' => $f ? ($f->low !== null ? 'medium' : 'low') : 'low',
                    'basis' => $f ? ['source' => 'harvest forecast', 'method' => $f->method, 'made_on' => $f->made_on?->toDateString(), 'range' => [$f->low, $f->high]]
                        : ['source' => 'expected yield × area', 'expected_yield' => $plan->expected_yield, 'hectares' => $plan->hectares()],
                    'data_as_of' => ($f?->made_on ?? today())->toDateString()]);
            }
            $ready = $f?->ready_on ?? $plan->planned_harvest_on;
            if ($ready && ! HarvestRecord::query()->where('crop_plan_id', $plan->id)->exists()) {
                $keep(['farm_id' => $plan->farm_id, 'model' => 'harvest_ready', 'subject_type' => 'crop_plan', 'subject_id' => $plan->id, 'predicted_date' => $ready,
                    'target_date' => $ready, 'confidence' => $f?->ready_on ? 'medium' : 'low',
                    'basis' => ['source' => $f?->ready_on ? 'harvest forecast ready date' : 'planned harvest date']]);
            }
        }

        foreach (Farm::query()->operational()->get() as $farm) {
            foreach ($this->risk->forFarm($farm) as $a) {
                $m = FarmMachine::query()->where('uuid', $a['machine']['id'])->first();
                $keep(['farm_id' => $farm->id, 'model' => 'machine_breakdown', 'subject_type' => 'machine', 'subject_id' => $m->id, 'predicted_value' => $a['score'],
                    'unit' => 'score', 'target_date' => today()->addDays(30), 'confidence' => $a['confidence'],
                    'basis' => ['risk' => $a['risk'], 'factors' => array_column($a['factors'], 'factor')]]);
            }
            foreach ($this->inventory->reorderSuggestions($farm) as $r) {
                $out = today()->addDays((int) floor($r['days_left']));
                $keep(['farm_id' => $farm->id, 'model' => 'stock_out', 'subject_type' => 'stock_item', 'subject_id' => $r['item_id'], 'predicted_date' => $out,
                    'predicted_value' => $r['available'], 'unit' => $r['uom'], 'target_date' => $out, 'confidence' => 'medium',
                    'basis' => ['per_day' => $r['per_day'], 'on_order' => $r['on_order'], 'farm' => $farm->code]]);
            }
        }

        return $n;
    }

    /** Score what can be scored now. */
    public function score(int $companyId): int
    {
        $n = 0;
        foreach (AgriPrediction::query()->whereNull('scored_at')->where('company_id', $companyId)->get() as $p) {
            $result = match ($p->model) {
                'yield' => $this->scoreYield($p),
                'harvest_ready' => $this->scoreReady($p),
                'machine_breakdown' => $p->target_date->lte(today()) ? $this->scoreBreakdown($p) : null,
                'stock_out' => $p->target_date->copy()->addDays(3)->lte(today()) ? $this->scoreStockOut($p) : null,
                default => null,
            };
            if ($result) {
                $p->update($result + ['scored_at' => now()]);
                $n++;
            }
        }

        return $n;
    }

    /** Each model's record on this organisation's own history. */
    public function evaluate(int $companyId): array
    {
        $all = AgriPrediction::query()->where('company_id', $companyId)->get()->groupBy('model');

        return collect(self::CARDS)->map(function ($card, $model) use ($all) {
            $rows = $all->get($model, collect());
            $scored = $rows->whereNotNull('scored_at');
            $metric = match ($model) {
                'yield' => $scored->filter(fn ($p) => $p->predicted_value > 0)->isEmpty() ? null : [
                    'mean_abs_error_pct' => round($scored->filter(fn ($p) => $p->predicted_value > 0)->avg(fn ($p) => abs($p->error) / $p->predicted_value * 100), 1),
                    'bias_pct' => round($scored->filter(fn ($p) => $p->predicted_value > 0)->avg(fn ($p) => $p->error / $p->predicted_value * 100), 1)],
                'harvest_ready' => $scored->isEmpty() ? null : ['mean_abs_days' => round($scored->avg(fn ($p) => abs($p->error)), 1), 'bias_days' => round($scored->avg('error'), 1)],
                'stock_out' => $scored->isEmpty() ? null : ['ran_out_or_nearly' => round($scored->filter(fn ($p) => $p->actual_date !== null)->count() / $scored->count() * 100, 1)],
                'machine_breakdown' => $scored->isEmpty() ? null : $scored->groupBy(fn ($p) => $p->basis['risk'] ?? 'unknown')->map(fn ($g) => [
                    'assessed' => $g->count(), 'broke_down' => $g->where('actual_value', 1)->count(), 'rate_pct' => round($g->where('actual_value', 1)->count() / $g->count() * 100, 1)])->all(),
            };
            $confidence = $scored->count() >= 30 ? 'medium' : 'low';

            return ['model' => $model, 'name' => $card['name'], 'predictions' => $rows->count(), 'scored' => $scored->count(), 'metric' => $metric,
                'confidence' => $confidence, 'limitations' => $card['limits'].($scored->count() < 10 ? ' Fewer than ten outcomes are known yet, so its record says little.' : ''),
                'data_as_of' => $rows->max('data_as_of')?->toDateString(), 'last_scored' => $scored->max('scored_at')?->toDateTimeString()];
        })->values()->all();
    }

    private function scoreYield(AgriPrediction $p): ?array
    {
        $plan = CropPlan::query()->find($p->subject_id);
        if (! $plan || $plan->status !== CropPlan::COMPLETED) {
            return null;
        }
        $actual = (float) HarvestRecord::query()->where('crop_plan_id', $plan->id)->where('destination', '!=', 'loss')->sum('quantity');

        return ['actual_value' => $actual, 'actual_date' => HarvestRecord::query()->where('crop_plan_id', $plan->id)->max('harvested_on'), 'error' => round($actual - $p->predicted_value, 4)];
    }

    private function scoreReady(AgriPrediction $p): ?array
    {
        $first = HarvestRecord::query()->where('crop_plan_id', $p->subject_id)->min('harvested_on');
        if (! $first) {
            return null;
        }

        return ['actual_date' => $first, 'error' => (float) $p->predicted_date->diffInDays(Carbon::parse($first), false)];
    }

    private function scoreBreakdown(AgriPrediction $p): array
    {
        $broke = MachineFault::query()->where('vehicle_id', $p->subject_id)->where('stops_machine', true)
            ->whereBetween('created_at', [$p->predicted_on->copy()->startOfDay(), $p->target_date->copy()->endOfDay()])->exists();

        return ['actual_value' => $broke ? 1 : 0, 'actual_date' => $broke ? MachineFault::query()->where('vehicle_id', $p->subject_id)->where('stops_machine', true)
            ->where('created_at', '>=', $p->predicted_on)->min('created_at') : null];
    }

    private function scoreStockOut(AgriPrediction $p): array
    {
        $item = FinStockItem::query()->find($p->subject_id);
        $farm = $p->farm_id ? Farm::query()->find($p->farm_id) : null;
        $left = $item && $farm ? array_sum(array_map(fn ($w) => $this->stock->available($item, (int) $w), $this->inventory->storesOf($farm)->pluck('warehouse_id')->all())) : null;
        $perDay = (float) ($p->basis['per_day'] ?? 0);
        $out = $left !== null && $left <= max(0.0, $perDay * 3);

        return ['actual_value' => $left, 'actual_date' => $out ? $p->predicted_date : null, 'error' => null];
    }
}
