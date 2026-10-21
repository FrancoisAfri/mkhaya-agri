<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropInputTemplate;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\Simulation;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §16E: try a production plan before committing to it.
 *
 * A base plan (area, crop and variety, planting date, expected yield, selling price, input costs,
 * labour, water, payment terms) and its alternatives are each worked out the same way: yield,
 * revenue, costs, margin, break-even, the month-by-month cash and the funding it needs, and the
 * margin in a poor and a good year. Every effect it applies is stated — and where it draws on the
 * farm's own history (planting month, yield variability), it says how much history there was.
 * Nothing here is a prediction of the season; it is a like-for-like comparison of choices.
 */
class DecisionSimulator
{
    public const CHANGES = ['planting_date', 'variety_id', 'yield_per_ha', 'price', 'input_cost_pct', 'labour_hours_per_ha', 'labour_rate', 'water_available_m3_per_ha', 'hectares', 'payment_days'];

    /** The base, filled from the farm's approved input rates and its history where not given. */
    public function base(Farm $farm, int $cropTypeId, array $data): array
    {
        $templates = CropInputTemplate::query()->where('crop_type_id', $cropTypeId)->where('status', 'approved')->where('basis', 'per_ha')->get();
        $inputsPerHa = $data['inputs_per_ha'] ?? round($templates->sum(function ($t) {
            $item = $t->stock_item_id ? FinStockItem::withoutGlobalScopes()->find($t->stock_item_id) : null;

            return (float) $t->rate * (float) ($item?->avg_cost ?: $item?->standard_cost ?: 0);
        }), 2);

        return array_merge(['hectares' => 10, 'planting_date' => today()->addMonth()->toDateString(), 'variety_id' => null, 'yield_per_ha' => null, 'yield_unit' => 't',
            'price' => null, 'inputs_per_ha' => $inputsPerHa, 'inputs_basis' => $data['inputs_per_ha'] ?? null ? 'as entered' : "{$templates->count()} approved input rate(s) at current stock cost",
            'labour_hours_per_ha' => 0, 'labour_rate' => 0, 'water_required_m3_per_ha' => 0, 'water_available_m3_per_ha' => null, 'water_cost_per_m3' => 0,
            'other_per_ha' => 0, 'payment_days' => 30, 'days_to_maturity' => 120], array_filter($data, fn ($v) => $v !== null && $v !== ''));
    }

    public function create(Farm $farm, array $data, User $actor): Simulation
    {
        $base = $this->base($farm, (int) $data['crop_type_id'], $data['base'] ?? []);
        if (empty($base['yield_per_ha']) || empty($base['price'])) {
            throw ValidationException::withMessages(['base' => 'Give the expected yield per hectare and the selling price.']);
        }
        $sim = Simulation::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'name' => $data['name'], 'field_id' => $data['field_id'] ?? null,
            'crop_type_id' => $data['crop_type_id'], 'base' => $base, 'scenarios' => $data['scenarios'] ?? [], 'created_by' => $actor->id]);

        return $this->run($sim);
    }

    public function run(Simulation $sim): Simulation
    {
        $history = $this->history($sim);
        $rows = [['name' => 'Base plan', 'changes' => []]];
        foreach ($sim->scenarios ?? [] as $s) {
            $rows[] = ['name' => $s['name'], 'changes' => array_intersect_key($s['changes'] ?? [], array_flip(self::CHANGES))];
        }
        $results = array_map(fn ($r) => ['name' => $r['name'], 'changes' => $r['changes']] + $this->evaluate(array_merge($sim->base, $r['changes']), $history), $rows);
        $sim->update(['results' => ['scenarios' => $results, 'history' => $history, 'ran_at' => now()->toDateTimeString()]]);

        return $sim;
    }

    /** One plan, worked out. */
    public function evaluate(array $p, array $history): array
    {
        $assumptions = [];
        $ha = (float) $p['hectares'];
        $days = ! empty($p['variety_id']) ? (int) (CropVariety::query()->whereKey($p['variety_id'])->value('days_to_maturity') ?: $p['days_to_maturity']) : (int) $p['days_to_maturity'];
        $plant = Carbon::parse($p['planting_date']);
        $harvest = $plant->copy()->addDays($days);
        $yield = (float) $p['yield_per_ha'];

        // Planting month: only where the farm's own completed crops show a difference.
        $month = (int) $plant->month;
        if (isset($history['by_month'][$month]) && $history['mean_per_ha'] > 0 && $history['by_month'][$month]['n'] >= 2) {
            $f = $history['by_month'][$month]['per_ha'] / $history['mean_per_ha'];
            $yield *= $f;
            $assumptions[] = 'Planted in '.$plant->format('F').': this farm\'s crops planted that month yielded '.round(($f - 1) * 100).'% against its average ('.$history['by_month'][$month]['n'].' crops).';
        } else {
            $assumptions[] = 'No planting-date effect applied: too few of this farm\'s past crops were planted in '.$plant->format('F').' to show one.';
        }
        // Water: a simple response — yield falls with the square root of the share of water supplied.
        if (! empty($p['water_available_m3_per_ha']) && (float) $p['water_required_m3_per_ha'] > 0 && $p['water_available_m3_per_ha'] < $p['water_required_m3_per_ha']) {
            $share = max(0, (float) $p['water_available_m3_per_ha'] / (float) $p['water_required_m3_per_ha']);
            $yield *= sqrt($share);
            $assumptions[] = 'Water short: '.round($share * 100).'% of the requirement supplied; yield reduced to '.round(sqrt($share) * 100).'% (a simple square-root response, not a crop model).';
        }
        $water = min((float) ($p['water_available_m3_per_ha'] ?? $p['water_required_m3_per_ha']), (float) $p['water_required_m3_per_ha']);
        $inputs = (float) $p['inputs_per_ha'] * (1 + (float) ($p['input_cost_pct'] ?? 0) / 100);
        $labour = (float) $p['labour_hours_per_ha'] * (float) $p['labour_rate'];
        $costPerHa = $inputs + $labour + $water * (float) $p['water_cost_per_m3'] + (float) $p['other_per_ha'];
        $revenue = $yield * $ha * (float) $p['price'];
        $costs = $costPerHa * $ha;
        $margin = $revenue - $costs;

        // Cash by month: inputs at planting, labour and water spread over the season, revenue on the buyer's terms after harvest.
        $months = [];
        $add = function (Carbon $on, float $amount) use (&$months) {
            $k = $on->format('Y-m');
            $months[$k] = round(($months[$k] ?? 0) + $amount, 2);
        };
        $add($plant, -$inputs * $ha);
        $seasonMonths = max(1, (int) ceil($days / 30));
        for ($i = 0; $i < $seasonMonths; $i++) {
            $add($plant->copy()->addMonths($i), -($labour + $water * (float) $p['water_cost_per_m3'] + (float) $p['other_per_ha']) * $ha / $seasonMonths);
        }
        $add($harvest->copy()->addDays((int) $p['payment_days']), $revenue);
        ksort($months);
        $run = 0;
        $low = 0;
        foreach ($months as $v) {
            $run += $v;
            $low = min($low, $run);
        }
        // Risk: the farm's own yield variability where it has enough history, else ±20%.
        $cv = $history['n'] >= 3 ? $history['cv'] : 0.2;
        $assumptions[] = $history['n'] >= 3 ? 'Poor/good year: ±'.round($cv * 128).'% yield (1.28 × the variability of this farm\'s last '.$history['n'].' crops).'
            : 'Poor/good year: ±25.6% yield (1.28 × an assumed 20% variability — too few past crops to measure it).';
        $poor = $yield * (1 - 1.28 * $cv) * $ha * (float) $p['price'] - $costs;
        $good = $yield * (1 + 1.28 * $cv) * $ha * (float) $p['price'] - $costs;

        return ['planting' => $plant->toDateString(), 'harvest' => $harvest->toDateString(), 'yield_per_ha' => round($yield, 3), 'unit' => $p['yield_unit'],
            'revenue' => round($revenue, 2), 'costs' => round($costs, 2), 'cost_per_ha' => round($costPerHa, 2), 'margin' => round($margin, 2), 'margin_per_ha' => $ha > 0 ? round($margin / $ha, 2) : null,
            'breakeven_yield_per_ha' => (float) $p['price'] > 0 ? round($costPerHa / (float) $p['price'], 3) : null, 'breakeven_price' => $yield > 0 ? round($costPerHa / $yield, 2) : null,
            'peak_funding' => round(-$low, 2), 'cash_by_month' => $months, 'margin_poor_year' => round($poor, 2), 'margin_good_year' => round($good, 2),
            'assumptions' => $assumptions, 'label' => 'Estimate for comparing choices, not a forecast of the season.'];
    }

    /** This farm's completed crops of the type: yield per hectare overall and by planting month. */
    private function history(Simulation $sim): array
    {
        $plans = CropPlan::query()->where('farm_id', $sim->farm_id)->where('crop_type_id', $sim->crop_type_id)->where('status', CropPlan::COMPLETED)->get();
        $rows = $plans->map(function ($p) {
            $ha = $p->hectares();
            $q = (float) HarvestRecord::query()->where('crop_plan_id', $p->id)->where('destination', '!=', 'loss')->sum('quantity');

            return $ha > 0 && $q > 0 ? ['per_ha' => $q / $ha, 'month' => (int) ($p->planted_on ?? $p->planned_planting_on)?->month] : null;
        })->filter()->values();
        $mean = $rows->avg('per_ha') ?? 0;
        $sd = $rows->count() > 1 ? sqrt($rows->map(fn ($r) => ($r['per_ha'] - $mean) ** 2)->sum() / ($rows->count() - 1)) : 0;

        return ['n' => $rows->count(), 'mean_per_ha' => round($mean, 3), 'cv' => $mean > 0 ? round($sd / $mean, 3) : 0,
            'by_month' => $rows->groupBy('month')->map(fn ($g) => ['n' => $g->count(), 'per_ha' => round($g->avg('per_ha'), 3)])->all()];
    }

    /** Turn the chosen scenario into a draft crop plan, approved the usual way. */
    public function adopt(Simulation $sim, string $scenario, int $seasonId, User $actor): CropPlan
    {
        $r = collect($sim->results['scenarios'] ?? [])->firstWhere('name', $scenario) ?? throw ValidationException::withMessages(['scenario' => 'Run the simulation first.']);
        if (! $sim->field_id) {
            throw ValidationException::withMessages(['field_id' => 'Name the field the plan is for.']);
        }
        $p = array_merge($sim->base, $r['changes']);
        $farm = Farm::query()->findOrFail($sim->farm_id);
        $field = \App\Modules\Agri\Models\FarmField::query()->where('farm_id', $farm->id)->findOrFail($sim->field_id);
        $plan = app(CropPlanService::class)->create($farm, $field, ['season_id' => $seasonId, 'crop_type_id' => $sim->crop_type_id,
            'variety_id' => $p['variety_id'] ?? null, 'code' => mb_strimwidth(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $sim->name)), 0, 12).'-'.$sim->id, 'planned_area' => $p['hectares'], 'area_unit' => 'ha',
            'planned_planting_on' => $r['planting'], 'planned_harvest_on' => $r['harvest'], 'expected_yield' => $r['yield_per_ha'], 'yield_unit' => $p['yield_unit'],
            'expected_price' => $p['price'], 'budget_amount' => $r['costs'], 'notes' => "From simulation \"{$sim->name}\", scenario \"{$scenario}\"."], $actor);
        $sim->update(['crop_plan_id' => $plan->id, 'chosen' => $scenario]);

        return $plan;
    }
}
