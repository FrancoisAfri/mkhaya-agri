<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Device;
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
            } elseif ($metered === null && $water <= 0 && $feed > 0) {
                $flags[] = 'No water recorded or metered';
            }
            if ($ratio !== null && ($ratio < $ratioBand[0] || $ratio > $ratioBand[1])) {
                $flags[] = "Water to feed {$ratio} is outside {$ratioBand[0]}–{$ratioBand[1]}";
            }
            $days[] = ['date' => $key, 'feed_kg' => $feed, 'water_recorded_l' => $water, 'water_metered_l' => $metered, 'ratio' => $ratio, 'flags' => $flags, 'ok' => ! $flags];
        }

        return $days;
    }

    /**
     * The panel a kind of production is run from: its own figures, daily series and the sensors in
     * its unit — lay rate for layers, milk per cow for a dairy herd, biomass and water quality for a
     * pond, climate and yield per plant for a tunnel, density and intake for broilers.
     *
     * @return array{tiles: list<array{label: string, value: ?float, unit: ?string, hint?: ?string}>, series: list<array{name: string, unit: string, points: list<array{x: string, y: float}>}>, sensors: list<array{metric: string, title: string}>}
     */
    public function panel(Cycle $cycle, int $days = 14): array
    {
        $def = $cycle->definition();
        $category = $def['category'] ?? null;
        $vars = $this->variables($cycle);
        $daily = fn (string $kind) => Record::query()->where('cycle_id', $cycle->id)->where('kind', $kind)->whereNull('corrects_id')
            ->where('recorded_at', '>=', today()->subDays($days - 1))->get()->groupBy(fn ($r) => $r->recorded_at->toDateString())
            ->map(fn ($g) => (float) $g->sum('quantity'))->sortKeys();
        $series = fn (string $name, string $unit, $byDay) => ['name' => $name, 'unit' => $unit, 'points' => collect($byDay)->map(fn ($v, $d) => ['x' => $d, 'y' => round((float) $v, 3)])->values()->all()];
        $alive = max(1, (int) ($cycle->current_count ?? 0));
        $unitArea = $cycle->unit?->area_unit === 'm2' ? (float) $cycle->unit->area : null;
        $tiles = [];
        $out = [];
        $sensors = [];
        switch ($category) {
            case 'poultry':
                if (($def['output_unit'] ?? null) === 'eggs') {
                    $eggs = $daily('eggs');
                    $tiles[] = ['label' => 'Hen-day lay', 'value' => $eggs->count() ? round($eggs->last() / $alive * 100, 1) : null, 'unit' => '%', 'hint' => 'last day'];
                    $tiles[] = ['label' => 'Eggs a day (7-day mean)', 'value' => $eggs->count() ? round($eggs->take(-7)->avg()) : null, 'unit' => 'eggs'];
                    $out[] = $series('Eggs collected', 'eggs', $eggs);
                    $out[] = $series('Hen-day lay', '%', $eggs->map(fn ($v) => $v / $alive * 100));
                } else {
                    $target = $def['defaults']['target_weight_kg'] ?? null;
                    $tiles[] = ['label' => 'Average weight', 'value' => $vars['avg_weight_kg'], 'unit' => 'kg', 'hint' => $target ? "target {$target} kg at day ".($def['defaults']['cycle_days'] ?? '—') : null];
                    $tiles[] = ['label' => 'Stocking density', 'value' => $unitArea ? round($alive / $unitArea, 2) : null, 'unit' => 'birds/m²'];
                    $last = Record::query()->where('cycle_id', $cycle->id)->where('kind', 'weight')->latest('recorded_at')->first();
                    $tiles[] = ['label' => 'Uniformity', 'value' => isset($last?->details['uniformity_pct']) ? (float) $last->details['uniformity_pct'] : null, 'unit' => '%'];
                }
                $out[] = $series('Feed per bird', 'g', $daily('feed')->map(fn ($v) => $v * 1000 / $alive));
                $out[] = $series('Water per bird', 'ml', $daily('water')->map(fn ($v) => $v * 1000 / $alive));
                $sensors = ['temperature', 'humidity', 'ammonia', 'co2', 'water_flow'];
                break;
            case 'dairy':
                $milk = $daily('milk');
                $tiles[] = ['label' => 'Milk per cow', 'value' => $milk->count() ? round($milk->last() / $alive, 2) : null, 'unit' => 'l', 'hint' => 'last day'];
                $tiles[] = ['label' => 'Herd milk (7-day mean)', 'value' => $milk->count() ? round($milk->take(-7)->avg(), 1) : null, 'unit' => 'l/day'];
                $out[] = $series('Milk', 'l', $milk);
                $out[] = $series('Milk per cow', 'l', $milk->map(fn ($v) => $v / $alive));
                break;
            case 'aquaculture':
                $biomass = $vars['avg_weight_kg'] !== null ? round($vars['avg_weight_kg'] * $alive, 1) : null;
                $tiles[] = ['label' => 'Biomass', 'value' => $biomass, 'unit' => 'kg', 'hint' => 'count × average weight'];
                $tiles[] = ['label' => 'Density', 'value' => $biomass && $unitArea ? round($biomass / $unitArea, 2) : null, 'unit' => 'kg/m²'];
                $feed = $daily('feed');
                $tiles[] = ['label' => 'Feed rate', 'value' => $biomass && $feed->count() ? round($feed->last() / $biomass * 100, 2) : null, 'unit' => '% biomass/day'];
                $out[] = $series('Feed', 'kg', $feed);
                $sensors = ['dissolved_oxygen', 'water_temperature', 'ph'];
                break;
            case 'greenhouse':
                $harvest = $daily('harvest');
                $tiles[] = ['label' => 'Yield per plant', 'value' => $cycle->opening_count ? round($vars['harvest_qty'] / $cycle->opening_count, 3) : null, 'unit' => $def['output_unit'] ?? 'kg'];
                $tiles[] = ['label' => 'Picked, last 7 days', 'value' => round($harvest->take(-7)->sum(), 1), 'unit' => $def['output_unit'] ?? 'kg'];
                $out[] = $series('Picked', $def['output_unit'] ?? 'kg', $harvest);
                $sensors = ['temperature', 'humidity', 'ec'];
                break;
            case 'crop':
                $plan = $cycle->cropPlan;
                $tiles[] = ['label' => 'Days since planting', 'value' => $plan?->planted_on ? (float) $plan->planted_on->diffInDays(today()) : null, 'unit' => 'days'];
                $tiles[] = ['label' => 'Expected', 'value' => $plan && $plan->expected_yield ? round((float) $plan->expected_yield * $plan->hectares(), 2) : null, 'unit' => $plan?->yield_unit];
                $tiles[] = ['label' => 'Harvested', 'value' => $vars['harvest_qty'], 'unit' => $plan?->yield_unit ?? $def['output_unit'] ?? null];
                $out[] = $series('Harvested', $plan?->yield_unit ?? 't', $daily('harvest'));
                // The field from the satellite: vegetation index by capture.
                $fieldId = $cycle->unit?->field_id ?? $plan?->field_id;
                if ($fieldId) {
                    $ndvi = \App\Modules\Agri\Models\FieldImagery::withoutGlobalScopes()->where('field_id', $fieldId)->where('index', 'ndvi')->orderBy('captured_on')->limit(30)->get();
                    if ($ndvi->isNotEmpty()) {
                        $tiles[] = ['label' => 'NDVI (satellite)', 'value' => round((float) $ndvi->last()->mean, 2), 'unit' => null,
                            'hint' => $ndvi->last()->captured_on?->toDateString().($ndvi->last()->change_pct !== null ? sprintf(', %+.0f%% since last', $ndvi->last()->change_pct) : '')];
                        $out[] = ['name' => 'NDVI (satellite)', 'unit' => 'index', 'points' => $ndvi->map(fn ($i) => ['x' => $i->captured_on?->toDateString(), 'y' => round((float) $i->mean, 3)])->values()->all()];
                    }
                }
                $sensors = ['soil_moisture', 'rain'];
                break;
            case 'processing':
                $tiles[] = ['label' => 'Made', 'value' => $cycle->output_quantity, 'unit' => $def['output_unit'] ?? null];
                $tiles[] = ['label' => 'Runs', 'value' => (float) Record::query()->where('cycle_id', $cycle->id)->where('link_type', 'packhouse_operation')->count(), 'unit' => null];
                $out[] = $series('Made', $def['output_unit'] ?? 'kg', $daily('harvest'));
                break;
            default:
                $out[] = $series('Feed', 'kg', $daily('feed'));
        }
        // Only the sensors this unit actually has.
        $present = $cycle->unit_id ? Device::query()->where('unit_id', $cycle->unit_id)->whereNotIn('kind', ['controller', 'gateway'])->pluck('metric')->unique()->all() : [];

        return ['tiles' => $tiles, 'series' => array_values(array_filter($out, fn ($s) => $s['points'] !== [])),
            'sensors' => collect($sensors)->intersect($present)->map(fn ($m) => ['metric' => $m, 'title' => ucfirst(str_replace('_', ' ', $m))])->values()->all()];
    }

    /**
     * What a cycle has cost, by what it was spent on — feed from the store, treatments, other inputs
     * and costs recorded, and labour from the farm tasks done for it — and per unit of what it made,
     * per animal and per hectare.
     */
    public function costs(Cycle $cycle): array
    {
        $records = $cycle->records()->whereNull('corrects_id')->get();
        $by = $records->groupBy('kind')->map(fn ($g) => round((float) $g->sum('cost'), 2))->filter(fn ($v) => $v > 0);
        $hours = (float) \App\Modules\Agri\Models\FarmTask::withoutGlobalScopes()->where('farm_id', $cycle->farm_id)
            ->where(fn ($q) => $q->when($cycle->livestock_group_id, fn ($w) => $w->orWhere('livestock_group_id', $cycle->livestock_group_id))
                ->when($cycle->crop_plan_id, fn ($w) => $w->orWhere('crop_plan_id', $cycle->crop_plan_id))->when(! $cycle->livestock_group_id && ! $cycle->crop_plan_id, fn ($w) => $w->whereRaw('1 = 0')))
            ->sum('labour_hours');
        $labour = round($hours * (float) config('manufacturing.agriculture.labour_rate', 35), 2);
        $lines = $by->mapWithKeys(fn ($v, $k) => [match ($k) { 'feed' => 'Feed', 'treatment' => 'Animal health', 'harvest' => 'Processing', 'cost' => 'Other costs', 'input' => 'Inputs', default => ucfirst($k) } => $v])->all();
        if ($labour > 0) {
            $lines['Labour ('.round($hours, 1).' h)'] = $labour;
        }
        $total = round(array_sum($lines), 2);
        $vars = $this->variables($cycle);
        $made = (float) ($vars['output_qty'] ?: $vars['harvest_qty'] ?: $vars['eggs'] ?: $vars['milk_l']);
        $def = $cycle->definition();

        return ['lines' => $lines, 'total' => $total,
            'per_output' => $made > 0 ? round($total / $made, 4) : null, 'output_unit' => $def['output_unit'] ?? null,
            'per_head' => $cycle->opening_count ? round($total / max(1, (int) ($cycle->current_count ?: $cycle->opening_count)), 2) : null, 'count_unit' => $def['count_unit'] ?? null,
            'per_area' => $cycle->area ? round($total / $cycle->area, 2) : null, 'area_unit' => $cycle->area_unit];
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
