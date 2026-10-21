<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Scenario;
use Illuminate\Validation\ValidationException;

/**
 * Scenario optimisation for a grow-out: how many to place and how long to grow them for.
 *
 * Growth is a power curve w = a·dᵇ fitted to the cycle's own weighings when it has three or more,
 * otherwise to the breed's standard; feed conversion rises with age, and mortality accrues at the
 * type's normal daily rate. Every combination is worked out — birds sold, kilograms, feed, revenue,
 * feed and placement cost, margin, and the stocking density at the end — and the best is the highest
 * margin per day of house time among those inside the density limit. A model to compare options, with
 * its assumptions shown; not a promise.
 */
class ScenarioPlanner
{
    /** Ross 308 as-hatched standard, kg by day. */
    public const STANDARD = [7 => 0.19, 14 => 0.49, 21 => 0.97, 28 => 1.55, 35 => 2.2, 42 => 2.85];

    public function __construct(private readonly Metrics $metrics) {}

    /**
     * @param  array{placements: list<int>, days: list<int>, chick_price: float, feed_price_kg: float, live_price_kg: float, area_m2?: ?float, max_density_kg_m2?: ?float}  $in
     */
    public function growOut(ProductionType $type, ?Cycle $cycle, array $in, User $actor, string $name): Scenario
    {
        $placements = array_values(array_unique(array_filter(array_map('intval', $in['placements'] ?? []), fn ($x) => $x > 0)));
        $days = array_values(array_unique(array_filter(array_map('intval', $in['days'] ?? []), fn ($x) => $x >= 7 && $x <= 120)));
        if (! $placements || ! $days || count($placements) * count($days) > 200) {
            throw ValidationException::withMessages(['placements' => 'Give up to 200 combinations of numbers placed and grow-out days (7–120).']);
        }
        foreach (['chick_price', 'feed_price_kg', 'live_price_kg'] as $k) {
            if (! isset($in[$k]) || (float) $in[$k] <= 0) {
                throw ValidationException::withMessages([$k => 'Give the '.str_replace('_', ' ', $k).'.']);
            }
        }
        $def = app(ProductionTypes::class)->definition($type);
        $points = $cycle ? $cycle->records()->where('kind', 'weight')->whereNull('corrects_id')->orderBy('recorded_at')->get()
            ->map(fn ($r) => [max(1, $cycle->started_on->diffInDays($r->recorded_at->copy()->startOfDay())), (float) $r->quantity])->values()->all() : [];
        $basis = count($points) >= 3 ? "this flock's weighings" : 'the breed standard';
        [$a, $b] = $this->fitPower(count($points) >= 3 ? $points : array_map(null, array_keys(self::STANDARD), array_values(self::STANDARD)));
        $dailyMort = (float) ($def['defaults']['daily_mortality_pct'] ?? 0.1) / 100;
        $area = (float) ($in['area_m2'] ?? $cycle?->unit?->area ?? 0) ?: null;
        $maxDensity = (float) ($in['max_density_kg_m2'] ?? 39);

        $rows = [];
        foreach ($placements as $n) {
            foreach ($days as $d) {
                $w = $a * $d ** $b;
                $alive = (int) round($n * (1 - $dailyMort) ** $d);
                $kg = $alive * $w;
                $fcr = 0.75 + 0.025 * $d;                                // cumulative FCR rises with age
                $feed = $kg * $fcr;
                $revenue = $kg * (float) $in['live_price_kg'];
                $cost = $feed * (float) $in['feed_price_kg'] + $n * (float) $in['chick_price'];
                $density = $area ? round($kg / $area, 1) : null;
                $rows[] = ['placed' => $n, 'days' => $d, 'avg_weight_kg' => round($w, 3), 'sold' => $alive, 'live_kg' => round($kg), 'fcr' => round($fcr, 2),
                    'feed_kg' => round($feed), 'revenue' => round($revenue), 'cost' => round($cost), 'margin' => round($revenue - $cost),
                    'margin_per_day' => round(($revenue - $cost) / $d), 'density_kg_m2' => $density, 'within_density' => $density === null || $density <= $maxDensity];
            }
        }
        $best = collect($rows)->where('within_density', true)->sortByDesc('margin_per_day')->first();

        return Scenario::create(['company_id' => $type->company_id, 'cycle_id' => $cycle?->id, 'production_type_id' => $type->id, 'name' => $name,
            'inputs' => $in + ['growth_basis' => $basis, 'growth_curve' => ['a' => round($a, 5), 'b' => round($b, 4)], 'daily_mortality_pct' => $dailyMort * 100,
                'fcr_model' => '0.75 + 0.025 × days', 'max_density_kg_m2' => $maxDensity, 'area_m2' => $area],
            'results' => $rows, 'best' => $best, 'model' => 'grow-out-power-curve 1.0', 'created_by' => $actor->id]);
    }

    /** w = a·dᵇ by least squares on the logs. */
    private function fitPower(array $points): array
    {
        $logs = array_map(fn ($p) => [log(max(1, $p[0])), log(max(0.0001, $p[1]))], $points);
        [$la, $b] = Forecasts::fit($logs);

        return [exp($la), $b];
    }
}
