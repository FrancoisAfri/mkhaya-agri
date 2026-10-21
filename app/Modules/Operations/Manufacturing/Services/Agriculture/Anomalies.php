<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Reading;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;

/**
 * Anomaly detection: a day that does not look like the days before it.
 *
 * For every running cycle, each daily series — feed, water, eggs, milk, deaths — and each sensor's
 * daily mean is compared with its own previous fortnight. A day more than three standard deviations
 * from that baseline (with a floor on the spread, so a very steady series is not flagged for a
 * rounding change) is an anomaly, reported with its baseline. Statistics, not a model to trust
 * blindly: it says "look here", with the numbers.
 */
class Anomalies
{
    public const Z = 3.0;

    /** @return list<array{cycle: Cycle, series: string, date: string, value: float, mean: float, sd: float, z: float, unit: ?string}> */
    public function detect(int $companyId): array
    {
        $out = [];
        foreach (Cycle::query()->where('company_id', $companyId)->where('status', Cycle::ACTIVE)->get() as $c) {
            foreach (['feed' => 'kg', 'water' => 'l', 'eggs' => 'eggs', 'milk' => 'l'] as $kind => $unit) {
                $daily = Record::query()->where('cycle_id', $c->id)->where('kind', $kind)->whereNull('corrects_id')->where('recorded_at', '>=', today()->subDays(15))
                    ->where('recorded_at', '<', today())->get()->groupBy(fn ($r) => $r->recorded_at->toDateString())->map(fn ($g) => (float) $g->sum('quantity'))->sortKeys();
                if ($a = $this->check($daily->all())) {
                    $out[] = ['cycle' => $c, 'series' => $kind, 'unit' => $unit] + $a;
                }
            }
            $deaths = Record::query()->where('cycle_id', $c->id)->whereIn('kind', Record::REMOVALS)->whereNull('corrects_id')->where('recorded_at', '>=', today()->subDays(15))
                ->where('recorded_at', '<', today())->get()->groupBy(fn ($r) => $r->recorded_at->toDateString())->map(fn ($g) => (float) abs($g->sum('count_delta')))->sortKeys();
            if ($c->opening_count && ($a = $this->check($deaths->all(), 2.0))) {
                $out[] = ['cycle' => $c, 'series' => 'deaths', 'unit' => $c->type?->count_unit] + $a;
            }
            if ($c->unit_id) {
                foreach (Device::query()->where('unit_id', $c->unit_id)->whereNotIn('kind', ['controller', 'gateway'])->whereNotNull('metric')->get() as $d) {
                    $daily = Reading::query()->where('device_id', $d->id)->where('measured_at', '>=', today()->subDays(15))->where('measured_at', '<', today())
                        ->selectRaw('date(measured_at) as d, avg(value) as v')->groupBy('d')->orderBy('d')->pluck('v', 'd')->map(fn ($v) => (float) $v)->all();
                    if ($a = $this->check($daily)) {
                        $out[] = ['cycle' => $c, 'series' => $d->metric.' ('.$d->name.')', 'unit' => $d->unit] + $a;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The last day against the ones before it.
     *
     * @param  array<string, float>  $daily  date => value, oldest first
     * @return array{date: string, value: float, mean: float, sd: float, z: float}|null
     */
    public function check(array $daily, float $minSpread = 0.0): ?array
    {
        if (count($daily) < 8) {
            return null;
        }
        $dates = array_keys($daily);
        $last = array_pop($dates);
        $value = $daily[$last];
        $base = array_values(array_intersect_key($daily, array_flip($dates)));
        $mean = array_sum($base) / count($base);
        $sd = sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $base)) / (count($base) - 1));
        $sd = max($sd, abs($mean) * 0.05, $minSpread, 1e-6);
        $z = ($value - $mean) / $sd;

        return abs($z) >= self::Z ? ['date' => $last, 'value' => round($value, 3), 'mean' => round($mean, 3), 'sd' => round($sd, 3), 'z' => round($z, 2)] : null;
    }
}
