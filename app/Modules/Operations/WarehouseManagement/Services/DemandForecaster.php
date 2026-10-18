<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Models\Forecast;
use App\Modules\Operations\WarehouseManagement\Models\ForecastRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demand forecasting (WMS-120) from what actually left: dispatches, sales, issues and production
 * issues, net of anything reversed.
 *
 * The method follows the demand: Croston's method where demand is intermittent (long gaps between
 * orders), a seasonal profile where two years of monthly history show one, exponential smoothing
 * otherwise, and a moving average for short histories. A spike far outside the item's usual demand
 * is treated as a one-off -- capped, and listed in the forecast's basis -- rather than learnt as
 * normal. Too little history gives no number at all, and says so: a forecast that cannot be
 * supported is not shown as if it could.
 */
class DemandForecaster
{
    use ScopesRawQueries;

    public const MIN_DEMAND_PERIODS = 3;

    private const OUTBOUND = ['dispatch', 'sale', 'issue', 'mfg_issue'];

    /** @param array{bucket?:string, horizon?:int, history?:int, warehouse_id?:?int, item_ids?:?array} $o */
    public function run(array $o, ?User $by): ForecastRun
    {
        $bucket = $o['bucket'] ?? 'MONTH';
        if (! in_array($bucket, ['WEEK', 'MONTH'], true)) {
            throw new RuntimeException('Forecast by week or by month.');
        }
        $horizon = max(1, min((int) ($o['horizon'] ?? 6), $bucket === 'WEEK' ? 52 : 24));
        $history = max(6, min((int) ($o['history'] ?? ($bucket === 'WEEK' ? 52 : 24)), $bucket === 'WEEK' ? 156 : 60));
        $companyId = (int) ($this->tenantId() ?? $by?->company_id);
        $itemIds = $o['item_ids'] ?? FinStockItem::query()->where('company_id', $companyId)->where('is_active', true)->pluck('id')->all();

        return DB::transaction(function () use ($bucket, $horizon, $history, $o, $by, $companyId, $itemIds) {
            $run = ForecastRun::create(['company_id' => $companyId, 'warehouse_id' => $o['warehouse_id'] ?? null, 'bucket' => $bucket, 'horizon' => $horizon,
                'history_periods' => $history, 'params' => ['alpha' => 0.3, 'croston_alpha' => 0.1, 'outlier_mads' => 5, 'interval' => '80%'], 'created_by' => $by?->id]);
            $starts = $this->periodStarts($bucket, $history);
            $series = $this->history($itemIds, $bucket, $starts, $o['warehouse_id'] ?? null);
            $future = $this->futureStarts($bucket, $horizon);
            $insufficient = 0;
            foreach ($itemIds as $itemId) {
                $values = array_map(fn ($s) => (float) ($series[$itemId][$s] ?? 0), $starts);
                if (array_sum($values) <= 0) {
                    continue; // nothing ever left: no demand to forecast and nothing to say
                }
                $result = $this->forecast($values, $starts, $bucket);
                if ($result['method'] === 'INSUFFICIENT') {
                    $insufficient++;
                }
                foreach ($future as $i => $start) {
                    $qty = $result['per_period'] === null ? null : max(0.0, $result['per_period'] * ($result['season'][$this->seasonKey($start, $bucket)] ?? 1.0));
                    Forecast::create(['company_id' => $companyId, 'run_id' => $run->id, 'item_id' => $itemId, 'period_start' => $start, 'method' => $result['method'],
                        'qty' => $qty === null ? null : round($qty, 4),
                        'lower' => $qty === null ? null : round(max(0.0, $qty - $result['spread']), 4), 'upper' => $qty === null ? null : round($qty + $result['spread'], 4),
                        'basis' => $i === 0 ? $result['basis'] : ['see' => 'first period']]);
                }
            }
            $run->update(['items' => Forecast::query()->where('run_id', $run->id)->distinct('item_id')->count('item_id'), 'insufficient' => $insufficient]);

            return $run->fresh();
        });
    }

    public function override(Forecast $f, float $qty, string $reason, User $by): Forecast
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why the forecast is being overridden: a promotion, a lost customer, a known order.');
        }
        if ($qty < 0) {
            throw new RuntimeException('A forecast cannot be negative.');
        }
        $f->update(['override_qty' => $qty, 'override_reason' => $reason, 'override_by' => $by->id]);

        return $f;
    }

    /** The latest run's view of an item: per-period effective quantities. @return array<string, float|null> */
    public function latestFor(int $itemId): array
    {
        $run = Forecast::query()->where('item_id', $itemId)->max('run_id');
        if (! $run) {
            return [];
        }

        return Forecast::query()->where('run_id', $run)->where('item_id', $itemId)->orderBy('period_start')->get()
            ->mapWithKeys(fn ($f) => [$f->period_start->toDateString() => $f->effective()])->all();
    }

    /** Daily rate from the latest run's first period, or null where there is none or it is unsupported. */
    public function dailyRate(int $itemId): ?array
    {
        $run = Forecast::query()->where('item_id', $itemId)->max('run_id');
        $f = $run ? Forecast::query()->where('run_id', $run)->where('item_id', $itemId)->orderBy('period_start')->first() : null;
        if (! $f || $f->effective() === null) {
            return null;
        }
        $days = ForecastRun::query()->whereKey($run)->value('bucket') === 'WEEK' ? 7 : 30.4;

        return ['daily' => $f->effective() / $days, 'method' => $f->method, 'overridden' => $f->override_qty !== null,
            'spread_daily' => ($f->upper !== null && $f->qty !== null) ? ((float) $f->upper - (float) $f->qty) / $days : null];
    }

    /**
     * Forecast accuracy over periods that have now happened: each period's forecast from the latest
     * run made before it began, against what actually left. WAPE and bias, per item and overall.
     *
     * @return array{overall: array, items: list<array>}
     */
    public function accuracy(int $periods = 6): array
    {
        $rows = DB::table('wms_forecasts as f')->join('wms_forecast_runs as r', 'r.id', '=', 'f.run_id')
            ->when($this->tenantId(), fn ($q, $c) => $q->where('f.company_id', $c))
            ->where('f.period_start', '<', now()->startOfMonth()->toDateString())->where('f.period_start', '>=', now()->subMonths($periods)->startOfMonth()->toDateString())
            ->whereRaw('r.created_at < f.period_start')->where('r.bucket', 'MONTH')->whereNotNull(DB::raw('COALESCE(f.override_qty, f.qty)'))
            ->orderByDesc('r.id')->get(['f.item_id', 'f.period_start', 'f.qty', 'f.override_qty', 'r.id as run'])
            ->unique(fn ($r) => $r->item_id.'|'.$r->period_start);
        if ($rows->isEmpty()) {
            return ['overall' => ['periods' => 0, 'wape' => null, 'bias' => null], 'items' => []];
        }
        $actual = $this->history($rows->pluck('item_id')->unique()->all(), 'MONTH', $rows->pluck('period_start')->unique()->values()->all(), null);
        $per = $rows->groupBy('item_id')->map(function ($g, $itemId) use ($actual) {
            $f = $g->sum(fn ($r) => (float) ($r->override_qty ?? $r->qty));
            $a = $g->sum(fn ($r) => (float) ($actual[$itemId][$r->period_start] ?? 0));
            $err = $g->sum(fn ($r) => abs((float) ($r->override_qty ?? $r->qty) - (float) ($actual[$itemId][$r->period_start] ?? 0)));

            return ['item_id' => (int) $itemId, 'periods' => $g->count(), 'forecast' => round($f, 2), 'actual' => round($a, 2),
                'wape' => $a > 0 ? round($err / $a * 100, 1) : null, 'bias' => $a > 0 ? round(($f - $a) / $a * 100, 1) : null, '_err' => $err];
        });
        $codes = DB::table('fin_stock_items')->whereIn('id', $per->keys())->pluck('code', 'id');
        $totalA = $per->sum('actual');

        return [
            'overall' => ['periods' => $rows->count(), 'wape' => $totalA > 0 ? round($per->sum('_err') / $totalA * 100, 1) : null,
                'bias' => $totalA > 0 ? round(($per->sum('forecast') - $totalA) / $totalA * 100, 1) : null],
            'items' => $per->map(fn ($r) => array_diff_key($r, ['_err' => 1]) + ['item' => $codes[$r['item_id']] ?? null])->sortByDesc('wape')->values()->all(),
        ];
    }

    // ── The arithmetic ───────────────────────────────────────────────────────────────────────

    /** @param list<float> $values oldest first */
    private function forecast(array $values, array $starts, string $bucket): array
    {
        $nonZero = array_values(array_filter($values, fn ($v) => $v > 0));
        $n = count($values);
        if (count($nonZero) < self::MIN_DEMAND_PERIODS) {
            return ['method' => 'INSUFFICIENT', 'per_period' => null, 'spread' => 0.0, 'season' => [],
                'basis' => ['reason' => 'Demand in only '.count($nonZero)." of {$n} periods; at least ".self::MIN_DEMAND_PERIODS.' are needed for a forecast.',
                    'history' => $values]];
        }

        // One-offs: far above the usual size of demand, they are capped at it and named.
        sort($nonZero);
        $median = $nonZero[intdiv(count($nonZero) - 1, 2)];
        $dev = array_map(fn ($v) => abs($v - $median), $nonZero);
        sort($dev);
        $mad = max($dev[intdiv(count($dev) - 1, 2)], $median * 0.1);
        $cap = max($median + 5 * $mad, $median * 3);
        $treated = [];
        $clean = $values;
        foreach ($values as $i => $v) {
            if ($v > $cap) {
                $treated[] = ['period' => $starts[$i], 'was' => $v, 'used' => round($median, 4)];
                $clean[$i] = $median;
            }
        }

        $adi = $n / max(1, count(array_filter($clean, fn ($v) => $v > 0)));
        $pattern = $adi > 1.32 ? 'INTERMITTENT' : 'REGULAR';
        $season = [];
        if ($pattern === 'INTERMITTENT') {
            [$perPeriod, $errors] = $this->croston($clean);
            $method = 'CROSTON';
        } elseif ($bucket === 'MONTH' && $n >= 24 && ($season = $this->seasonality($clean, $starts)) !== []) {
            $deseason = array_map(fn ($v, $s) => $v / ($season[$this->seasonKey($s, $bucket)] ?: 1), $clean, $starts);
            [$perPeriod, $errors] = $this->smooth($deseason, 0.3);
            $method = 'SEASONAL';
        } elseif ($n < 6) {
            $last = array_slice($clean, -3);
            $perPeriod = array_sum($last) / count($last);
            $errors = array_map(fn ($v) => $v - $perPeriod, $last);
            $method = 'MOVING_AVERAGE';
        } else {
            [$perPeriod, $errors] = $this->smooth($clean, 0.3);
            $method = 'SMOOTHING';
        }
        $rmse = $errors ? sqrt(array_sum(array_map(fn ($e) => $e * $e, $errors)) / count($errors)) : 0.0;

        return ['method' => $method, 'per_period' => $perPeriod, 'spread' => 1.28 * $rmse, 'season' => $season,
            'basis' => ['pattern' => $pattern, 'adi' => round($adi, 2), 'periods' => $n, 'demand_periods' => count($nonZero), 'median' => round($median, 4),
                'one_offs' => $treated, 'history' => $values, 'season' => $season ? array_map(fn ($v) => round($v, 3), $season) : null]];
    }

    /** Simple exponential smoothing; returns the final level and the one-step errors. */
    private function smooth(array $values, float $alpha): array
    {
        $level = $values[0];
        $errors = [];
        foreach (array_slice($values, 1) as $v) {
            $errors[] = $v - $level;
            $level = $alpha * $v + (1 - $alpha) * $level;
        }

        return [$level, $errors];
    }

    /** Croston: smooth the size of demand and the interval between demands separately. */
    private function croston(array $values, float $alpha = 0.1): array
    {
        $z = $p = null;
        $gap = 1;
        $errors = [];
        foreach ($values as $v) {
            if ($z !== null) {
                $errors[] = $v - $z / $p;
            }
            if ($v > 0) {
                $z = $z === null ? $v : $alpha * $v + (1 - $alpha) * $z;
                $p = $p === null ? $gap : $alpha * $gap + (1 - $alpha) * $p;
                $gap = 1;
            } else {
                $gap++;
            }
        }

        return [$z !== null && $p ? $z / $p : 0.0, $errors];
    }

    /** Month-of-year indices, only where they differ enough from flat to be worth using. */
    private function seasonality(array $values, array $starts): array
    {
        $mean = array_sum($values) / count($values);
        if ($mean <= 0) {
            return [];
        }
        $byMonth = [];
        foreach ($values as $i => $v) {
            $byMonth[$this->seasonKey($starts[$i], 'MONTH')][] = $v;
        }
        $index = array_map(fn ($vs) => (array_sum($vs) / count($vs)) / $mean, $byMonth);
        $spread = max($index) - min($index);

        return $spread >= 0.4 && count($index) === 12 ? $index : [];
    }

    private function seasonKey(string $start, string $bucket): string
    {
        return $bucket === 'MONTH' ? Carbon::parse($start)->format('m') : Carbon::parse($start)->format('W');
    }

    /** @return list<string> period starts, oldest first, ending with the last complete period */
    private function periodStarts(string $bucket, int $count): array
    {
        $end = $bucket === 'MONTH' ? now()->startOfMonth() : now()->startOfWeek();

        return array_reverse(array_map(fn ($i) => ($bucket === 'MONTH' ? $end->copy()->subMonthsNoOverflow($i) : $end->copy()->subWeeks($i))->toDateString(), range(1, $count)));
    }

    /** @return list<string> */
    private function futureStarts(string $bucket, int $horizon): array
    {
        $start = $bucket === 'MONTH' ? now()->startOfMonth() : now()->startOfWeek();

        return array_map(fn ($i) => ($bucket === 'MONTH' ? $start->copy()->addMonthsNoOverflow($i) : $start->copy()->addWeeks($i))->toDateString(), range(0, $horizon - 1));
    }

    /** item => period start => quantity that left, net of reversals. @return array<int, array<string, float>> */
    private function history(array $itemIds, string $bucket, array $starts, ?int $warehouseId): array
    {
        if ($itemIds === [] || $starts === []) {
            return [];
        }
        $trunc = $bucket === 'MONTH' ? 'month' : 'week';
        $rows = DB::table('fin_stock_movements as m')->whereIn('m.item_id', $itemIds)->whereIn('m.type', self::OUTBOUND)->where('m.qty', '<', 0)
            ->whereNull('m.reversal_of_id')->whereNotExists(fn ($q) => $q->from('fin_stock_movements as r')->whereColumn('r.reversal_of_id', 'm.id'))
            ->where('m.movement_date', '>=', min($starts))
            ->when($warehouseId, fn ($q, $v) => $q->where('m.warehouse_id', $v))
            ->when($this->tenantId(), fn ($q, $c) => $q->where('m.company_id', $c))
            ->groupBy('m.item_id', DB::raw("date_trunc('{$trunc}', m.movement_date)"))
            ->selectRaw("m.item_id, date_trunc('{$trunc}', m.movement_date)::date AS p, SUM(ABS(m.qty)) AS q")->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->item_id][$r->p] = (float) $r->q;
        }

        return $out;
    }
}
