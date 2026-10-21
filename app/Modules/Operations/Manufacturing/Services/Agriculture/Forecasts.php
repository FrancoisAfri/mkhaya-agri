<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Forecast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Forecasts made by statistical models, each kept with the model, its version, the inputs, an
 * interval — and, once the day comes, the actual and the error. Accuracy is measured from those,
 * per model, so a model is trusted for what it has been right about.
 *
 * The models are deliberately simple and explainable: a least-squares trend through a cycle's
 * weighings for live weight, and the type's expected yield adjusted by the cycle's own history for
 * output. An AI model may be added beside them; it is measured the same way.
 */
class Forecasts
{
    public const WEIGHT_MODEL = ['linear-trend', '1.0'];

    public function __construct(private readonly Metrics $metrics) {}

    /** Live weight on a date, from the cycle's weighings. Null when there are fewer than three. */
    public function weight(Cycle $cycle, Carbon $on): ?Forecast
    {
        $points = $cycle->records()->where('kind', 'weight')->whereNull('corrects_id')->orderBy('recorded_at')->get()
            ->map(fn ($r) => [(float) $cycle->started_on->diffInDays($r->recorded_at->copy()->startOfDay()), (float) $r->quantity])->values();
        if ($points->count() < 3) {
            return null;
        }
        [$a, $b, $sd] = self::fit($points->all());
        $x = (float) $cycle->started_on->diffInDays($on->copy()->startOfDay());
        $y = $a + $b * $x;
        [$model, $version] = self::WEIGHT_MODEL;

        return Forecast::create(['company_id' => $cycle->company_id, 'farm_id' => $cycle->farm_id, 'cycle_id' => $cycle->id, 'metric' => 'avg_weight_kg',
            'target_on' => $on->toDateString(), 'predicted' => round($y, 4), 'lower' => round($y - 1.96 * $sd, 4), 'upper' => round($y + 1.96 * $sd, 4),
            'unit' => 'kg', 'model' => $model, 'model_version' => $version, 'made_at' => now(),
            'inputs' => ['points' => $points->count(), 'intercept' => round($a, 5), 'slope_per_day' => round($b, 5), 'residual_sd' => round($sd, 5), 'day' => $x]]);
    }

    /**
     * Head count at a date: today's count less the recent daily death rate carried forward, with an
     * interval from how much that rate varies day to day.
     */
    public function count(Cycle $cycle, Carbon $on, int $window = 7): ?Forecast
    {
        if ($cycle->current_count === null || ! $cycle->opening_count) {
            return null;
        }
        $daily = collect($this->metrics->dailyMortality($cycle, $window))->pluck('deaths')->map(fn ($n) => (float) $n);
        $days = max(0, (int) today()->diffInDays($on->copy()->startOfDay(), false));
        $mean = $daily->avg() ?? 0.0;
        $sd = $daily->count() > 1 ? sqrt($daily->map(fn ($x) => ($x - $mean) ** 2)->sum() / ($daily->count() - 1)) : 0.0;
        $now = (float) $cycle->current_count;
        $loss = $mean * $days;
        $spread = 1.96 * $sd * sqrt(max(1, $days));

        return $this->store($cycle, 'head_count', $on, max(0, $now - $loss), max(0, $now - $loss - $spread), min($now, $now - $loss + $spread),
            $cycle->type?->count_unit ?? 'head', 'mortality-run-rate', '1.0', ['window_days' => $window, 'mean_daily_deaths' => round($mean, 2), 'sd' => round($sd, 2), 'days_ahead' => $days]);
    }

    /** Eggs (or litres of milk) on a date: the recent daily mean, with its spread. */
    public function output(Cycle $cycle, Carbon $on, string $kind = 'eggs', int $window = 7): ?Forecast
    {
        $rows = $cycle->records()->where('kind', $kind)->whereNull('corrects_id')->where('recorded_at', '>=', today()->subDays($window))->get()
            ->groupBy(fn ($r) => $r->recorded_at->toDateString())->map(fn ($g) => (float) $g->sum('quantity'))->values();
        if ($rows->count() < 3) {
            return null;
        }
        $mean = $rows->avg();
        $sd = sqrt($rows->map(fn ($x) => ($x - $mean) ** 2)->sum() / ($rows->count() - 1));

        return $this->store($cycle, $kind === 'milk' ? 'milk_l' : 'eggs', $on, $mean, max(0, $mean - 1.96 * $sd), $mean + 1.96 * $sd, $kind === 'milk' ? 'l' : 'eggs',
            'moving-average', '1.0', ['window_days' => $window, 'days' => $rows->count(), 'mean' => round($mean, 2), 'sd' => round($sd, 2)]);
    }

    /**
     * A crop cycle's harvest: the plan's expected yield on its area, corrected by how earlier cycles
     * of the same type did against their own plans; the interval is the spread of those ratios.
     */
    public function yield(Cycle $cycle): ?Forecast
    {
        $plan = $cycle->cropPlan;
        if (! $plan || ! (float) $plan->expected_yield) {
            return null;
        }
        // Expected yield is per hectare, as everywhere the farm uses it.
        $expected = (float) $plan->expected_yield * $plan->hectares();
        $ratios = Cycle::query()->where('production_type_id', $cycle->production_type_id)->where('status', Cycle::CLOSED)->whereKeyNot($cycle->id)
            ->whereNotNull('crop_plan_id')->get()->map(function (Cycle $c) {
                $p = $c->cropPlan;
                $exp = $p ? (float) $p->expected_yield * $p->hectares() : 0;

                return $exp > 0 ? (float) $c->output_quantity / $exp : null;
            })->filter()->values();
        $r = $ratios->count() ? $ratios->avg() : 1.0;
        $sd = $ratios->count() > 1 ? sqrt($ratios->map(fn ($x) => ($x - $r) ** 2)->sum() / ($ratios->count() - 1)) : 0.15;
        $on = $plan->planned_harvest_on ?? $cycle->expected_end_on ?? today()->addMonth();

        return $this->store($cycle, 'harvest_qty', Carbon::parse($on), $expected * $r, max(0, $expected * ($r - 1.96 * $sd)), $expected * ($r + 1.96 * $sd),
            $plan->yield_unit ?? 't', 'plan-adjusted', '1.0', ['expected' => round($expected, 3), 'history_ratio' => round($r, 3), 'history_cycles' => $ratios->count(), 'ratio_sd' => round($sd, 3)]);
    }

    /**
     * How likely the cycle is to be heading into a disease problem, 0–100: a logistic score over the
     * last three days' mortality against normal, the trend in water use, open environment alarms and
     * outbreak exposure of the farm. A screening score to prompt a look, not a diagnosis.
     */
    public function diseaseRisk(Cycle $cycle): Forecast
    {
        $def = $cycle->definition();
        $normal = (float) ($def['defaults']['daily_mortality_pct'] ?? 0.1);
        $recent = collect($this->metrics->dailyMortality($cycle, 3))->pluck('pct')->filter(fn ($x) => $x !== null);
        $mortalityRatio = $normal > 0 && $recent->count() ? $recent->avg() / $normal : 0;
        $water = DB::table('prd_records')->where('cycle_id', $cycle->id)->where('kind', 'water')->whereNull('corrects_id')->where('recorded_at', '>=', today()->subDays(6))
            ->selectRaw('date(recorded_at) as d, sum(quantity) as q')->groupBy('d')->orderBy('d')->pluck('q')->map(fn ($x) => (float) $x)->values();
        $waterDrop = $water->count() >= 4 ? max(0, 1 - $water->slice(-2)->avg() / max(1e-6, $water->slice(0, -2)->avg())) : 0;
        $alarms = DB::table('prd_incidents')->where('unit_id', $cycle->unit_id)->whereIn('status', ['open', 'acknowledged', 'in_progress'])->where('kind', 'threshold')->count();
        $exposure = DB::table('prd_exposures')->where('farm_id', $cycle->farm_id)->where('status', '!=', 'closed')->orderByRaw("case band when 'inside' then 0 when 'near' then 1 else 2 end")->value('band');
        $z = -3.0 + 0.9 * min($mortalityRatio, 6) + 6.0 * min($waterDrop, 0.5) + 0.6 * min($alarms, 3) + (['inside' => 2.0, 'near' => 1.0, 'watch' => 0.3][$exposure] ?? 0);
        $score = round(100 / (1 + exp(-$z)), 1);

        return $this->store($cycle, 'disease_risk', today(), $score, null, null, 'score', 'risk-logistic', '1.0', ['mortality_vs_normal' => round($mortalityRatio, 2),
            'water_drop' => round($waterDrop, 3), 'environment_alarms' => $alarms, 'exposure' => $exposure, 'note' => 'A screening score to prompt a look, never a diagnosis.']);
    }

    private function store(Cycle $cycle, string $metric, Carbon $on, float $predicted, ?float $lower, ?float $upper, ?string $unit, string $model, string $version, array $inputs): Forecast
    {
        return Forecast::create(['company_id' => $cycle->company_id, 'farm_id' => $cycle->farm_id, 'cycle_id' => $cycle->id, 'metric' => $metric,
            'target_on' => $on->toDateString(), 'predicted' => round($predicted, 4), 'lower' => $lower === null ? null : round($lower, 4), 'upper' => $upper === null ? null : round($upper, 4),
            'unit' => $unit, 'model' => $model, 'model_version' => $version, 'made_at' => now(), 'inputs' => $inputs]);
    }

    /** Record what actually happened against every forecast for that metric and date. */
    public function resolve(Forecast $f, float $actual): Forecast
    {
        $err = $actual - (float) $f->predicted;
        $f->update(['actual' => $actual, 'error' => round($err, 4), 'abs_pct_error' => abs($actual) > 1e-9 ? round(abs($err) / abs($actual) * 100, 4) : null,
            'within_interval' => $f->lower !== null && $f->upper !== null ? ($actual >= $f->lower && $actual <= $f->upper) : null, 'resolved_at' => now()]);

        return $f->fresh();
    }

    /** Resolve forecasts whose date has a measurement: a weighing on that day. */
    public function resolveDue(): int
    {
        $n = 0;
        Forecast::query()->whereNull('actual')->where('metric', '!=', 'disease_risk')->whereDate('target_on', '<', today())->whereNotNull('cycle_id')->each(function (Forecast $f) use (&$n) {
            $records = DB::table('prd_records')->where('cycle_id', $f->cycle_id)->whereNull('corrects_id');
            $actual = match ($f->metric) {
                'avg_weight_kg' => (clone $records)->where('kind', 'weight')->whereDate('recorded_at', $f->target_on)->avg('quantity'),
                'eggs' => (clone $records)->where('kind', 'eggs')->whereDate('recorded_at', $f->target_on)->sum('quantity') ?: null,
                'milk_l' => (clone $records)->where('kind', 'milk')->whereDate('recorded_at', $f->target_on)->sum('quantity') ?: null,
                // The count that evening: what was placed less every removal up to then.
                'head_count' => ($c = Cycle::query()->find($f->cycle_id)) && $c->opening_count
                    ? $c->opening_count + (int) (clone $records)->whereDate('recorded_at', '<=', $f->target_on)->sum('count_delta') : null,
                'harvest_qty' => ($c = Cycle::query()->find($f->cycle_id)) && $c->status === Cycle::CLOSED ? (float) $c->output_quantity : null,
                default => null,
            };
            if ($actual !== null) {
                $this->resolve($f, (float) $actual);
                $n++;
            }
        });

        return $n;
    }

    /** Make the day's forecasts for every running cycle, each by the models that fit it. */
    public function forecastAll(int $companyId): int
    {
        $n = 0;
        foreach (Cycle::query()->where('company_id', $companyId)->where('status', Cycle::ACTIVE)->with('type')->get() as $c) {
            $made = Forecast::query()->where('cycle_id', $c->id)->whereDate('made_at', today())->pluck('metric')->all();
            $end = Carbon::parse($c->expected_end_on ?? today()->addWeek());
            $category = $c->type?->category;
            $jobs = [
                'avg_weight_kg' => fn () => in_array($category, ['poultry', 'livestock', 'aquaculture'], true) ? $this->weight($c, $end->isPast() ? today()->addWeek() : $end) : null,
                'head_count' => fn () => $c->opening_count ? $this->count($c, $end->isPast() ? today()->addWeek() : $end) : null,
                'eggs' => fn () => $c->type?->output_unit === 'eggs' ? $this->output($c, today()->addDay(), 'eggs') : null,
                'milk_l' => fn () => $category === 'dairy' ? $this->output($c, today()->addDay(), 'milk') : null,
                'harvest_qty' => fn () => $c->crop_plan_id ? $this->yield($c) : null,
                'disease_risk' => fn () => in_array($category, ['poultry', 'livestock', 'dairy', 'aquaculture'], true) ? $this->diseaseRisk($c) : null,
            ];
            foreach ($jobs as $metric => $job) {
                if (! in_array($metric, $made, true) && $job()) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** @return list<array{model: string, version: string, metric: string, forecasts: int, mape: ?float, bias: ?float, coverage: ?float}> */
    public function accuracy(?int $companyId = null): array
    {
        return Forecast::query()->when($companyId, fn ($q) => $q->where('company_id', $companyId))->whereNotNull('actual')
            ->selectRaw('model, model_version, metric, count(*) as n, avg(abs_pct_error) as mape, avg(error) as bias, avg(case when within_interval then 1.0 else 0.0 end) as coverage')
            ->groupBy('model', 'model_version', 'metric')->orderBy('metric')->get()
            ->map(fn ($r) => ['model' => $r->model, 'version' => $r->model_version, 'metric' => $r->metric, 'forecasts' => (int) $r->n,
                'mape' => $r->mape !== null ? round((float) $r->mape, 2) : null, 'bias' => $r->bias !== null ? round((float) $r->bias, 4) : null,
                'coverage' => $r->coverage !== null ? round((float) $r->coverage * 100, 1) : null])->all();
    }

    /** Least squares: intercept, slope and the residual standard deviation. */
    public static function fit(array $points): array
    {
        $n = count($points);
        $mx = array_sum(array_column($points, 0)) / $n;
        $my = array_sum(array_column($points, 1)) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($y - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $my - $b * $mx;
        $ss = 0.0;
        foreach ($points as [$x, $y]) {
            $ss += ($y - ($a + $b * $x)) ** 2;
        }

        return [$a, $b, $n > 2 ? sqrt($ss / ($n - 2)) : 0.0];
    }
}
