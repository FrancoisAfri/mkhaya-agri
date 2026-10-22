<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AgriOrgSetting;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PeerBenchmark;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Peer benchmarks: how a crop did against farms like it — same crop, season and region — across
 * organisations that chose to take part.
 *
 * ┌──────────────────────────────────────────────────────────────────────────────────────────────┐
 * │ THIS IS THE ONE PLACE IN MKHAYA AGRI THAT DELIBERATELY WORKS ACROSS ORGANISATIONS.           │
 * │                                                                                              │
 * │ compute() runs outside any tenant (TenantContext::run(null)): it reads only the opt-in       │
 * │ setting of every organisation, then visits each opted-in organisation INSIDE that            │
 * │ organisation's own tenant context — so row-level security still confines every raw read to  │
 * │ that one organisation — and keeps from it nothing but anonymous numbers in memory: one       │
 * │ yield, cost per hectare and cost per tonne per farm, tagged with crop code, season and a     │
 * │ coarse region. What it writes is aggregates only (P25, median, P75, how many farms and       │
 * │ organisations) into agri_benchmarks, which has no company, farm or field column at all.      │
 * │                                                                                              │
 * │ Rules:                                                                                       │
 * │  - an organisation contributes, and sees benchmarks, only when it has opted in (default off);│
 * │  - a group is written only with at least MIN_FARMS distinct farms from at least MIN_ORGS     │
 * │    organisations (k-anonymity), so no figure can be traced to one farm;                      │
 * │  - the region is a province, or a 1° grid square from the farm's location — never a point.  │
 * └──────────────────────────────────────────────────────────────────────────────────────────────┘
 */
class PeerBenchmarks
{
    public const MIN_FARMS = 5;

    public const MIN_ORGS = 3;

    public const METRICS = ['yield_t_ha', 'cost_per_ha', 'cost_per_t'];

    public function __construct(private readonly TenantContext $tenants, private readonly CropProfitability $profit) {}

    // ── The cross-organisation job ──────────────────────────────────────────────────────────

    /** @return array{organisations: int, contributions: int, groups: int, written: int} */
    public function compute(): array
    {
        // The job acts for the system, not for whoever is signed in: farm access must not narrow a
        // tenant's own figures to the farms one person happens to reach.
        $person = \Illuminate\Support\Facades\Auth::user();
        \Illuminate\Support\Facades\Auth::forgetUser();
        try {
            return $this->computeAsSystem();
        } finally {
            if ($person) {
                \Illuminate\Support\Facades\Auth::setUser($person);
            }
        }
    }

    private function computeAsSystem(): array
    {
        return $this->tenants->run(null, function () {
            $started = now();
            // Only the opt-in flag is read across organisations.
            $companies = AgriOrgSetting::withoutGlobalScopes()->where('benchmarks_opt_in', true)->pluck('company_id')->map(fn ($id) => (int) $id)->all();

            // Each organisation's own figures, read inside its own tenant context (RLS applies).
            // Farm identity is reduced to an opaque in-memory key used only to count distinct farms.
            $points = [];
            foreach ($companies as $companyId) {
                $rows = $this->tenants->run($companyId, fn () => $this->contributions($companyId));
                foreach ($rows as $r) {
                    $points[] = $r + ['org' => hash('sha256', 'org:'.$companyId), 'farm' => hash('sha256', "farm:{$companyId}:{$r['farm_id']}")];
                }
            }

            $groups = [];
            foreach ($points as $p) {
                unset($p['farm_id']);
                $groups["{$p['season_key']}|{$p['crop_code']}|{$p['region_key']}"][] = $p;
            }
            $written = 0;
            DB::transaction(function () use ($groups, $started, &$written) {
                $ids = [];
                foreach ($groups as $g) {
                    array_push($ids, ...$this->writeGroup($g, $started));
                }
                $written = count($ids);
                // Groups that no longer qualify (an organisation left, a plan was corrected) go.
                PeerBenchmark::query()->where('seeded', false)->whereNotIn('id', $ids ?: [0])->delete();
            });

            return ['organisations' => count($companies), 'contributions' => count($points), 'groups' => count($groups), 'written' => $written];
        });
    }

    /** One season × crop × region: per-farm figures (farms with several plans are one farm), then the band if k-anonymous. */
    /** @return list<int> the bands written */
    private function writeGroup(array $points, Carbon $at): array
    {
        $first = $points[0];
        $ids = [];
        foreach (self::METRICS as $metric) {
            $byCurrency = $metric === 'yield_t_ha' ? ['' => $points] : collect($points)->groupBy('currency')->map->all()->all();
            foreach ($byCurrency as $currency => $ps) {
                $farms = collect($ps)->groupBy('farm')->map(function ($g) use ($metric) {
                    $ha = $g->sum('ha');
                    $t = $g->sum('tonnes');
                    $cost = $g->sum('cost');
                    $hasCost = $g->every(fn ($x) => $x['cost'] !== null);

                    return ['org' => $g->first()['org'], 'value' => match ($metric) {
                        'yield_t_ha' => $ha > 0 ? $t / $ha : null,
                        'cost_per_ha' => $hasCost && $ha > 0 ? $cost / $ha : null,
                        'cost_per_t' => $hasCost && $t > 0 ? $cost / $t : null,
                    }];
                })->filter(fn ($f) => $f['value'] !== null && $f['value'] > 0);
                if ($farms->count() < self::MIN_FARMS || $farms->pluck('org')->unique()->count() < self::MIN_ORGS) {
                    continue;      // too few to hide any one farm: nothing is written
                }
                $values = $farms->pluck('value')->sort()->values()->all();
                $ids[] = PeerBenchmark::query()->updateOrCreate(
                    ['season_key' => $first['season_key'], 'crop_code' => $first['crop_code'], 'region_key' => $first['region_key'], 'metric' => $metric, 'currency' => (string) $currency],
                    ['crop_name' => $first['crop_name'], 'region_label' => $first['region_label'], 'farms' => $farms->count(), 'organisations' => $farms->pluck('org')->unique()->count(),
                        'p25' => round(self::percentile($values, 25), 3), 'median' => round(self::percentile($values, 50), 3), 'p75' => round(self::percentile($values, 75), 3),
                        'seeded' => false, 'computed_at' => $at])->id;
            }
        }

        return $ids;
    }

    /** Linear interpolation between closest ranks (the spreadsheet PERCENTILE). */
    public static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        $rank = ($p / 100) * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);

        return (float) $sorted[$lo] + ($rank - $lo) * ((float) $sorted[$hi] - (float) $sorted[$lo]);
    }

    // ── One organisation's own figures (always read in its own tenant context) ─────────────

    /**
     * The organisation's harvested crops in tonnes, each with season, crop, region and the farm it
     * is on (the farm id stays inside the organisation's own computation).
     *
     * @return list<array{farm_id: int, season_key: string, crop_code: string, crop_name: string, region_key: string, region_label: string, currency: string, ha: float, tonnes: float, cost: ?float}>
     */
    public function contributions(int $companyId): array
    {
        $out = [];
        $plans = CropPlan::withoutGlobalScope('farm_access')->where('company_id', $companyId)
            ->whereIn('status', [CropPlan::HARVESTING, CropPlan::COMPLETED])->with(['cropType', 'farm'])->get();
        foreach ($plans as $plan) {
            if ($f = $this->planFigures($plan)) {
                $out[] = $f;
            }
        }

        return $out;
    }

    /** A plan's comparable figures, or null when it cannot be compared (no tonnes, no area, no region). */
    public function planFigures(CropPlan $plan): ?array
    {
        $farm = $plan->farm ?? Farm::withoutGlobalScopes()->find($plan->farm_id);
        $region = $farm ? self::region($farm) : null;
        $unit = strtolower((string) $plan->yield_unit);
        if (! $region || ! $plan->cropType || ! in_array($unit, ['t', 'kg'], true)) {
            return null;
        }
        $qty = (float) HarvestRecord::withoutGlobalScope('farm_access')->where('crop_plan_id', $plan->id)->where('destination', '!=', 'loss')->sum('quantity');
        $tonnes = $unit === 'kg' ? $qty / 1000 : $qty;
        $ha = $plan->hectares();
        if ($tonnes <= 0 || $ha <= 0) {
            return null;
        }
        try {
            $cost = (float) $this->profit->forPlan($plan)['total_costs'];
        } catch (\Throwable $e) {
            report($e);
            $cost = 0.0;
        }

        return ['farm_id' => (int) $plan->farm_id, 'season_key' => self::seasonKey($plan->planted_on ?? $plan->planned_planting_on), 'crop_code' => strtoupper($plan->cropType->code),
            'crop_name' => $plan->cropType->name, 'region_key' => $region['key'], 'region_label' => $region['label'], 'currency' => (string) ($farm->currency ?: 'ZAR'),
            'ha' => round($ha, 4), 'tonnes' => round($tonnes, 3), 'cost' => $cost > 0 ? round($cost, 2) : null];
    }

    /** Summer crops planted August–January belong to that summer; anything planted February–July to that winter. */
    public static function seasonKey(Carbon $planted): string
    {
        $y = (int) $planted->year;
        if ($planted->month >= 8) {
            return "S{$y}/".substr((string) ($y + 1), 2);
        }
        if ($planted->month === 1) {
            return 'S'.($y - 1).'/'.substr((string) $y, 2);
        }

        return "W{$y}";
    }

    /** The province the farm records (settings.province), else the 1° grid square it lies in. */
    public static function region(Farm $farm): ?array
    {
        $province = trim((string) ($farm->settings['province'] ?? ''));
        if ($province !== '') {
            return ['key' => 'province:'.Str::slug($province), 'label' => $province];
        }
        if ($farm->latitude === null || $farm->longitude === null) {
            return null;
        }
        $lat = (int) floor((float) $farm->latitude);
        $lng = (int) floor((float) $farm->longitude);

        return ['key' => "grid:{$lat}:{$lng}", 'label' => sprintf('%d–%d°%s, %d–%d°%s', abs($lat + ($lat < 0 ? 1 : 0)), abs($lat + ($lat < 0 ? 0 : 1)), $lat < 0 ? 'S' : 'N',
            abs($lng), abs($lng + 1), $lng < 0 ? 'W' : 'E')];
    }

    // ── What the organisation sees ──────────────────────────────────────────────────────────

    public function optIn(int $companyId, bool $on, User $actor): AgriOrgSetting
    {
        $s = AgriOrgSetting::for($companyId);
        $s->update(['benchmarks_opt_in' => $on, 'benchmarks_opted_at' => now(), 'benchmarks_opted_by' => $actor->id]);

        return $s;
    }

    /** Each of the organisation's crop plans beside its peer band; nothing at all unless it has opted in. */
    public function forCompany(int $companyId, ?Farm $farm = null): array
    {
        $setting = AgriOrgSetting::for($companyId);
        if (! $setting->benchmarks_opt_in) {
            return ['opted_in' => false, 'rows' => [], 'bands' => 0];
        }
        $plans = CropPlan::query()->where('company_id', $companyId)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING, CropPlan::COMPLETED])->with(['cropType', 'farm', 'field', 'season'])->orderByDesc('planned_planting_on')->get();
        $rows = [];
        foreach ($plans as $plan) {
            $farmRow = $plan->farm;
            $region = $farmRow ? self::region($farmRow) : null;
            if (! $plan->cropType || ! $region) {
                continue;
            }
            $season = self::seasonKey($plan->planted_on ?? $plan->planned_planting_on);
            $own = $this->planFigures($plan);
            $bands = PeerBenchmark::query()->where('season_key', $season)->where('crop_code', strtoupper($plan->cropType->code))->where('region_key', $region['key'])
                ->where(fn ($q) => $q->where('currency', '')->orWhere('currency', $farmRow->currency ?: 'ZAR'))->get()->keyBy('metric');
            $rows[] = ['plan' => $plan->code, 'plan_id' => $plan->uuid, 'crop' => $plan->cropType->name, 'field' => $plan->field?->code, 'farm' => $farmRow->name,
                'season' => $season, 'region' => $region['label'], 'status' => $plan->status, 'currency' => $farmRow->currency ?: 'ZAR',
                'own' => $own ? ['yield_t_ha' => round($own['tonnes'] / $own['ha'], 2), 'cost_per_ha' => $own['cost'] !== null ? round($own['cost'] / $own['ha'], 0) : null,
                    'cost_per_t' => $own['cost'] !== null ? round($own['cost'] / $own['tonnes'], 0) : null] : null,
                'bands' => collect(self::METRICS)->mapWithKeys(fn ($m) => [$m => ($b = $bands[$m] ?? null) ? ['p25' => $b->p25, 'median' => $b->median, 'p75' => $b->p75,
                    'farms' => $b->farms, 'organisations' => $b->organisations, 'computed_at' => $b->computed_at?->toDateString(), 'seeded' => $b->seeded] : null])->all()];
        }

        return ['opted_in' => true, 'opted_at' => $setting->benchmarks_opted_at?->format('Y-m-d'), 'rows' => $rows, 'bands' => PeerBenchmark::query()->count()];
    }
}
