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
        Forecast::query()->whereNull('actual')->where('metric', 'avg_weight_kg')->whereDate('target_on', '<=', today())->whereNotNull('cycle_id')->each(function (Forecast $f) use (&$n) {
            $w = DB::table('prd_records')->where('cycle_id', $f->cycle_id)->where('kind', 'weight')->whereNull('corrects_id')
                ->whereDate('recorded_at', $f->target_on)->avg('quantity');
            if ($w !== null) {
                $this->resolve($f, (float) $w);
                $n++;
            }
        });

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
