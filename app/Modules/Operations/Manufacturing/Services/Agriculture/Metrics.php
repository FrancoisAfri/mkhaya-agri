<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Reading;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Record;
use Illuminate\Support\Carbon;

/**
 * A cycle's performance: what was measured (counts, feed, water, weights, output, cost) and the
 * metrics its type's formulas make of it — livability, FCR, water per bird, yield per hectare,
 * cost per unit — by the version of the type the cycle started on.
 */
class Metrics
{
    /** @return array<string, float|int|null> */
    public function variables(Cycle $cycle, ?Carbon $until = null): array
    {
        $records = $cycle->records()->when($until, fn ($q) => $q->where('recorded_at', '<=', $until))->whereNull('corrects_id')->get();
        $sum = fn (string $kind, string $col = 'quantity') => (float) $records->where('kind', $kind)->sum($col);
        $opening = (int) $cycle->opening_count;
        $mortality = (int) abs($records->where('kind', 'mortality')->sum('count_delta'));
        $culls = (int) abs($records->where('kind', 'cull')->sum('count_delta'));
        $current = $cycle->current_count !== null ? (int) $cycle->current_count : null;
        $latest = $records->where('kind', 'weight')->sortBy('recorded_at')->last();
        $avg = $latest ? (float) $latest->quantity : null;
        $def = $cycle->definition();
        $chick = (float) ($cycle->custom['opening_weight_kg'] ?? $def['defaults']['opening_weight_kg'] ?? ($def['category'] === 'poultry' ? 0.042 : 0));

        return [
            'opening_count' => $opening ?: null,
            'current_count' => $current,
            'mortality' => $mortality,
            'culls' => $culls,
            'feed_kg' => $sum('feed'),
            'water_l' => $sum('water'),
            'avg_weight_kg' => $avg,
            'live_weight_kg' => $avg !== null && $current !== null ? round($avg * $current, 3) : null,
            'opening_weight_kg' => $opening ? round($chick * $opening, 3) : 0,
            'output_qty' => (float) $cycle->output_quantity ?: $sum('harvest'),
            'harvest_qty' => $sum('harvest'),
            'eggs' => $sum('eggs'),
            'milk_l' => $sum('milk'),
            'area' => $cycle->area ?: null,
            'cost' => round((float) $records->sum('cost'), 2),
            'age_days' => $cycle->ageDays($until) ?: null,
            'treatments' => $records->where('kind', 'treatment')->count(),
        ];
    }

    /** @return list<array{code: string, name: string, value: ?float, unit: ?string, better: ?string, formula: string}> */
    public function compute(Cycle $cycle, ?Carbon $until = null): array
    {
        $vars = $this->variables($cycle, $until);

        return collect($cycle->definition()['metrics'] ?? [])->map(fn ($m) => [
            'code' => $m['code'], 'name' => $m['name'], 'unit' => $m['unit'] ?? null, 'better' => $m['better'] ?? null, 'formula' => $m['formula'],
            'value' => Formula::evaluate($m['formula'], $vars),
        ])->values()->all();
    }

    public function value(Cycle $cycle, string $code): ?float
    {
        return collect($this->compute($cycle))->firstWhere('code', $code)['value'] ?? null;
    }

    /**
     * Feed and water, day by day: what was recorded against what the meters saw, and the water to
     * feed ratio. A day is flagged when feed is missing, the meter and the record disagree by more
     * than the tolerance, or the ratio leaves the band the type expects.
     *
     * @return list<array<string, mixed>>
     */
    public function reconcile(Cycle $cycle, Carbon $from, Carbon $to, float $tolerancePct = 10, array $ratioBand = [1.5, 2.5]): array
    {
        $tz = ($cycle->farm_id ? \App\Modules\Agri\Models\Farm::withoutGlobalScopes()->whereKey($cycle->farm_id)->value('timezone') : null) ?: config('app.timezone');
        // The days are the farm's days: midnight to midnight where the farm is.
        $from = Carbon::parse($from->toDateString(), $tz)->startOfDay();
        $to = Carbon::parse($to->toDateString(), $tz)->endOfDay();
        $records = $cycle->records()->whereIn('kind', ['feed', 'water'])->whereNull('corrects_id')
            ->whereBetween('recorded_at', [$from, $to])->get()
            ->groupBy(fn ($r) => $r->recorded_at->copy()->setTimezone($tz)->toDateString());
        $meters = Reading::query()->where('unit_id', $cycle->unit_id)->where('metric', 'water_meter')
            ->whereBetween('measured_at', [$from, $to])->orderBy('measured_at')->get()
            ->groupBy(fn ($r) => $r->measured_at->copy()->setTimezone($tz)->toDateString());

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $feed = (float) ($records[$key] ?? collect())->where('kind', 'feed')->sum('quantity');
            $water = (float) ($records[$key] ?? collect())->where('kind', 'water')->sum('quantity');
            $m = $meters[$key] ?? collect();
            $metered = $m->count() >= 2 ? round((float) $m->last()->value - (float) $m->first()->value, 2) : null;
            $ratio = $feed > 0 && ($metered ?? $water) > 0 ? round(($metered ?? $water) / $feed, 2) : null;
            $flags = [];
            if ($feed <= 0) {
                $flags[] = 'No feed recorded';
            }
            if ($metered !== null && $water > 0 && abs($metered - $water) / max($metered, 0.001) * 100 > $tolerancePct) {
                $flags[] = sprintf('Meter shows %.0f l, records show %.0f l', $metered, $water);
            }
            if ($metered !== null && $water <= 0) {
                $flags[] = 'Water used but not recorded';
            }
            if ($ratio !== null && ($ratio < $ratioBand[0] || $ratio > $ratioBand[1])) {
                $flags[] = "Water to feed {$ratio} is outside {$ratioBand[0]}–{$ratioBand[1]}";
            }
            $days[] = ['date' => $key, 'feed_kg' => $feed, 'water_recorded_l' => $water, 'water_metered_l' => $metered, 'ratio' => $ratio, 'flags' => $flags, 'ok' => ! $flags];
        }

        return $days;
    }

    /** Daily mortality as a share of the birds alive that morning, newest last. */
    public function dailyMortality(Cycle $cycle, int $days = 7): array
    {
        $out = [];
        $alive = (int) $cycle->current_count;
        $deaths = Record::query()->where('cycle_id', $cycle->id)->whereIn('kind', Record::REMOVALS)->whereNull('corrects_id')
            ->where('recorded_at', '>=', today()->subDays($days - 1))->get()->groupBy(fn ($r) => $r->recorded_at->toDateString());
        for ($i = 0; $i < $days; $i++) {
            $d = today()->subDays($i)->toDateString();
            $n = (int) abs(($deaths[$d] ?? collect())->sum('count_delta'));
            $start = $alive + $n;
            $out[$d] = ['date' => $d, 'deaths' => $n, 'pct' => $start > 0 ? round($n / $start * 100, 3) : null];
            $alive = $start;
        }

        return array_values(array_reverse($out));
    }
}
