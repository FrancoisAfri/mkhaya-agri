<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropCoefficient;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropStageEvent;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\IrrigationEvent;
use App\Modules\Agri\Models\IrrigationRecommendation;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\SoilMoistureReading;
use App\Modules\Agri\Models\WaterBalanceDay;
use App\Modules\Agri\Models\WeatherReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Irrigation scheduling from evapotranspiration — the FAO-56 single-coefficient soil-water balance.
 *
 * Every day, for each irrigation zone (or an irrigated field without zones) that has a crop in the
 * ground:
 *
 *     ETc = ET0 × Kc
 *     Dr(end of day) = Dr(start) − effective rain − net irrigation + ETc,   0 ≤ Dr ≤ TAW
 *
 *  - ET0 is the farm's reference evapotranspiration: the provider's (Open-Meteo sends FAO
 *    Penman-Monteith), else Hargreaves from the day's minimum and maximum temperature.
 *  - Kc follows the crop's FAO-56 curve over its season (initial, development, mid, late), moved on
 *    when a recorded growth stage says the crop is further along.
 *  - TAW = (field capacity − wilting point) × root depth, from the field's soil type; RAW = p × TAW,
 *    with p adjusted for the day's ETc as FAO-56 does.
 *  - Effective rain: under 2 mm is lost to interception and evaporation, 80 % of the rest counts;
 *    what the root zone cannot hold drains.
 *  - Irrigation given is net of the system's application losses (drip 90 %, pivot 85 %…).
 *  - A soil-moisture reading on a day replaces the modelled depletion with the measured one.
 *
 * Days before today are the record; today and the next six run on the forecast (and on irrigation
 * already planned). The first forecast day on which depletion reaches RAW becomes a recommendation:
 * refill the root zone, in mm and in m³ for the zone's area. It is advice — a person turns it into a
 * planned irrigation; nothing here switches a pump.
 */
class WaterBalance
{
    public const HISTORY_DAYS = 45;

    public const FORECAST_DAYS = 7;

    public const FAO56 = 'FAO-56 (Allen et al., 1998), Tables 11, 12 and 22';

    /** code => [Kc ini, Kc mid, Kc end, ini %, dev %, mid %, late %, root depth m, p] — FAO-56 values for Southern African practice. */
    public const DEFAULTS = [
        'MAIZE-W' => [0.30, 1.20, 0.60, 20, 27, 33, 20, 1.20, 0.55],
        'MAIZE-Y' => [0.30, 1.20, 0.60, 20, 27, 33, 20, 1.20, 0.55],
        'WHEAT' => [0.30, 1.15, 0.40, 15, 19, 44, 22, 1.20, 0.55],
        'SORGHUM' => [0.30, 1.00, 0.55, 16, 28, 32, 24, 1.20, 0.55],
        'BARLEY' => [0.30, 1.15, 0.25, 13, 21, 41, 25, 1.10, 0.55],
        'SOY' => [0.40, 1.15, 0.50, 15, 22, 44, 19, 0.90, 0.50],
        'SUNFLOWER' => [0.35, 1.00, 0.35, 19, 27, 35, 19, 1.00, 0.45],
        'CANOLA' => [0.35, 1.10, 0.35, 15, 25, 35, 25, 1.00, 0.60],
        'GROUNDNUT' => [0.40, 1.15, 0.60, 19, 27, 35, 19, 0.60, 0.50],
        'DRYBEAN' => [0.40, 1.15, 0.35, 18, 27, 37, 18, 0.70, 0.45],
        'POTATO' => [0.50, 1.15, 0.75, 19, 23, 35, 23, 0.50, 0.35],
        'TOMATO' => [0.60, 1.15, 0.80, 22, 30, 30, 18, 0.90, 0.40],
        'CABBAGE' => [0.70, 1.05, 0.95, 24, 36, 30, 10, 0.60, 0.45],
        'ONION' => [0.70, 1.05, 0.75, 10, 17, 46, 27, 0.40, 0.30],
        'CITRUS' => [0.70, 0.65, 0.70, 16, 25, 33, 26, 1.10, 0.50],
        'APPLE' => [0.60, 0.95, 0.75, 10, 33, 43, 14, 1.50, 0.50],
        'GRAPE' => [0.30, 0.85, 0.45, 8, 17, 50, 25, 1.50, 0.35],
        'AVOCADO' => [0.60, 0.85, 0.75, 25, 25, 25, 25, 0.80, 0.70],
        'MACADAMIA' => [0.65, 0.75, 0.70, 25, 25, 25, 25, 1.00, 0.50],    // not in FAO-56; close to other evergreen nut trees
        'SUGARCANE' => [0.40, 1.25, 0.75, 9, 15, 47, 29, 1.50, 0.65],
        'COTTON' => [0.35, 1.18, 0.60, 15, 26, 31, 28, 1.30, 0.65],
        'LUCERNE' => [0.40, 0.95, 0.90, 10, 20, 50, 20, 1.50, 0.55],    // averaged over cuttings
        'OATS-FOD' => [0.30, 1.15, 0.25, 15, 25, 45, 15, 1.00, 0.55],
        'TEFF' => [0.50, 0.90, 0.85, 10, 20, 50, 20, 0.80, 0.60],       // as grass hay, averaged over cuttings
    ];

    /** For a crop the catalogue does not know: a mild curve, flagged so someone sets the real one. */
    public const GENERIC = [0.40, 1.00, 0.70, 20, 30, 30, 20, 0.80, 0.50];

    /** Soil texture => [field capacity %, wilting point %] by volume (FAO-56 Table 19, mid values). */
    public const SOILS = [
        'sand' => [10, 4], 'loamy_sand' => [14, 6], 'sandy_loam' => [23, 10], 'sandy_clay_loam' => [27, 14], 'loam' => [31, 15],
        'silt_loam' => [33, 16], 'clay_loam' => [36, 20], 'silty_clay' => [40, 24], 'clay' => [42, 27],
    ];

    /** Share of what is pumped that reaches the root zone, by irrigation system. */
    public const EFFICIENCY = ['drip' => 0.90, 'micro' => 0.85, 'centre_pivot' => 0.85, 'sprinkler' => 0.75, 'flood' => 0.60, 'other' => 0.75];

    /** Crop stages as recorded (CropLifecycle::STAGES) => the FAO-56 stage they are in (0 ini, 1 dev, 2 mid, 3 late). */
    private const STAGE_PHASE = ['emergence' => 0, 'vegetative' => 1, 'flowering' => 2, 'fruit_set' => 2, 'grain_fill' => 2, 'maturity' => 3, 'harvest_ready' => 3];

    public function __construct(private readonly Irrigation $irrigation) {}

    // ── Reference ET ────────────────────────────────────────────────────────────────────────

    /** Hargreaves–Samani ET0 (mm/day) from the day's temperatures and the site's extraterrestrial radiation (FAO-56 eq. 52). */
    public static function hargreaves(float $tMin, float $tMax, float $latitude, int $dayOfYear): float
    {
        $phi = deg2rad($latitude);
        $dr = 1 + 0.033 * cos(2 * M_PI * $dayOfYear / 365);
        $delta = 0.409 * sin(2 * M_PI * $dayOfYear / 365 - 1.39);
        $ws = acos(max(-1.0, min(1.0, -tan($phi) * tan($delta))));
        $ra = (24 * 60 / M_PI) * 0.0820 * $dr * ($ws * sin($phi) * sin($delta) + cos($phi) * cos($delta) * sin($ws));   // MJ/m²/day
        $tMean = ($tMin + $tMax) / 2;

        return round(max(0.0, 0.0023 * ($tMean + 17.8) * sqrt(max(0.0, $tMax - $tMin)) * 0.408 * $ra), 2);
    }

    /** FAO-56's effective rainfall used here: under 2 mm is lost, 80 % of the rest reaches the root zone. */
    public static function effectiveRain(float $rain): float
    {
        return $rain < 2.0 ? 0.0 : round(0.8 * $rain, 2);
    }

    /** [field capacity %, wilting point %, texture key] for a field's soil type, read loosely (English and Afrikaans). */
    public static function soil(?string $soilType): array
    {
        $s = mb_strtolower((string) $soilType);
        $s = str_replace(['klei', 'leem', 'sanderige', 'slik'], ['clay', 'loam', 'sandy', 'silt'], $s);
        $key = match (true) {
            str_contains($s, 'loamy sand') => 'loamy_sand',
            str_contains($s, 'sandy clay loam') => 'sandy_clay_loam',
            str_contains($s, 'sandy loam') => 'sandy_loam',
            str_contains($s, 'silt loam') || str_contains($s, 'silty loam') => 'silt_loam',
            str_contains($s, 'clay loam') => 'clay_loam',
            str_contains($s, 'silty clay') => 'silty_clay',
            str_contains($s, 'loam') => 'loam',
            str_contains($s, 'clay') => 'clay',
            str_contains($s, 'sand') => 'sand',
            default => 'loam',
        };

        return [...self::SOILS[$key], $key];
    }

    // ── Crop coefficients ───────────────────────────────────────────────────────────────────

    /** Give every crop type of the organisation its FAO-56 coefficients, once; edits are never overwritten. */
    public function ensureCoefficients(int $companyId): void
    {
        $have = CropCoefficient::withoutGlobalScopes()->where('company_id', $companyId)->pluck('crop_type_id')->all();
        foreach (CropType::withoutGlobalScopes()->where('company_id', $companyId)->whereNotIn('id', $have)->get() as $type) {
            $known = self::DEFAULTS[strtoupper($type->code)] ?? null;
            [$ini, $mid, $end, $a, $b, $c, $d, $root, $p] = $known ?? self::GENERIC;
            CropCoefficient::withoutGlobalScopes()->create(['company_id' => $companyId, 'crop_type_id' => $type->id, 'kc_ini' => $ini, 'kc_mid' => $mid, 'kc_end' => $end,
                'stage_ini_pct' => $a, 'stage_dev_pct' => $b, 'stage_mid_pct' => $c, 'stage_late_pct' => $d, 'root_depth_m' => $root, 'depletion_p' => $p,
                'source' => $known ? self::FAO56 : 'Generic starting values — set this crop\'s own coefficients']);
        }
    }

    public function saveCoefficient(CropCoefficient $kc, array $data, User $actor): CropCoefficient
    {
        if ((int) $data['stage_ini_pct'] + (int) $data['stage_dev_pct'] + (int) $data['stage_mid_pct'] + (int) $data['stage_late_pct'] !== 100) {
            throw ValidationException::withMessages(['stage_ini_pct' => 'The four stage lengths are shares of the season and add up to 100.']);
        }
        $kc->update(array_intersect_key($data, array_flip(['kc_ini', 'kc_mid', 'kc_end', 'stage_ini_pct', 'stage_dev_pct', 'stage_mid_pct', 'stage_late_pct',
            'root_depth_m', 'depletion_p', 'source'])) + ['updated_by' => $actor->id]);

        return $kc;
    }

    /**
     * Kc and root depth on a day of a crop's season, and the FAO-56 stage (0–3). Null when the day is
     * outside the season. A recorded stage further along than the calendar moves the curve on.
     *
     * @return array{kc: float, root_m: float, phase: int}|null
     */
    public function cropDay(CropCoefficient $c, Carbon $start, Carbon $end, Carbon $day, ?string $recordedStage = null, bool $perennial = false): ?array
    {
        $len = max(1, $start->diffInDays($end));
        $f = $start->diffInDays($day, false) / $len;
        if ($f < 0 || $f > 1) {
            return null;
        }
        $a = $c->stage_ini_pct / 100;
        $b = $a + $c->stage_dev_pct / 100;
        $m = $b + $c->stage_mid_pct / 100;
        $bounds = [0, $a, $b, $m, 1];
        $phase = $f < $a ? 0 : ($f < $b ? 1 : ($f < $m ? 2 : 3));
        $seen = $recordedStage !== null ? (self::STAGE_PHASE[$recordedStage] ?? null) : null;
        if ($seen !== null && $seen > $phase) {
            $phase = $seen;
            $f = $bounds[$phase];          // the crop is at the start of the stage it was seen in
        }
        $kc = match ($phase) {
            0 => $c->kc_ini,
            1 => $c->kc_ini + ($c->kc_mid - $c->kc_ini) * (($f - $a) / max(0.0001, $b - $a)),
            2 => $c->kc_mid,
            default => $c->kc_mid + ($c->kc_end - $c->kc_mid) * (($f - $m) / max(0.0001, 1 - $m)),
        };
        // Roots reach full depth at the end of development; trees and perennials are there already.
        $zMin = min(0.3, $c->root_depth_m);
        $root = $perennial ? $c->root_depth_m : $zMin + ($c->root_depth_m - $zMin) * min(1.0, $f / max(0.0001, $b));

        return ['kc' => round($kc, 2), 'root_m' => round($root, 2), 'phase' => $phase];
    }

    // ── The daily balance ───────────────────────────────────────────────────────────────────

    /** Recompute a farm at most every half hour, so a screen that opens it is current without a cron. */
    public function computeIfStale(Farm $farm, int $minutes = 30): void
    {
        Cache::remember("agri_water_balance:{$farm->id}", now()->addMinutes($minutes), function () use ($farm) {
            $this->computeFarm($farm);

            return now()->toIso8601String();
        });
    }

    /** @return array{scopes: int, days: int, recommendations: int} */
    public function computeFarm(Farm $farm, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $this->ensureCoefficients((int) $farm->company_id);
        $weather = $this->dailyWeather($farm, $today->copy()->subDays(self::HISTORY_DAYS + 1), $today->copy()->addDays(self::FORECAST_DAYS));
        $out = ['scopes' => 0, 'days' => 0, 'recommendations' => 0];
        foreach ($this->scopes($farm) as $scope) {
            $r = $this->computeScope($farm, $scope, $weather, $today);
            if ($r !== null) {
                $out['scopes']++;
                $out['days'] += $r['days'];
                $out['recommendations'] += $r['recommendations'];
            }
        }
        Cache::put("agri_water_balance:{$farm->id}", now()->toIso8601String(), now()->addMinutes(30));

        return $out;
    }

    /**
     * What the balance runs on: every active zone, and every irrigated field that has none.
     *
     * @return list<array{key: string, field: FarmField, zone: ?IrrigationZone, area_ha: ?float, efficiency: float}>
     */
    public function scopes(Farm $farm): array
    {
        $zones = IrrigationZone::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('is_active', true)->get();
        $fields = FarmField::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->where('status', 'active')->get()->keyBy('id');
        $out = [];
        foreach ($zones as $z) {
            if ($field = $fields[$z->field_id] ?? null) {
                $out[] = ['key' => "z:{$z->id}", 'field' => $field, 'zone' => $z, 'area_ha' => $z->area_ha ?: $field->hectares(),
                    'efficiency' => self::EFFICIENCY[$z->system ?: $field->irrigation] ?? 0.75];
            }
        }
        foreach ($fields as $field) {
            if (! $zones->contains('field_id', $field->id) && $field->irrigation && $field->irrigation !== 'dryland') {
                $out[] = ['key' => "f:{$field->id}", 'field' => $field, 'zone' => null, 'area_ha' => $field->hectares(), 'efficiency' => self::EFFICIENCY[$field->irrigation] ?? 0.75];
            }
        }

        return $out;
    }

    /**
     * The farm's weather by day: ET0 (provider, else Hargreaves, else the day before's), rain, and
     * whether it is an observation. Observed days win over the forecast's daily rows, which win
     * over the hourly forecast summed.
     *
     * @return array<string, array{et0: ?float, source: ?string, rain: float}>
     */
    public function dailyWeather(Farm $farm, Carbon $from, Carbon $to): array
    {
        $rows = WeatherReading::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)->whereNull('field_id')
            ->whereIn('kind', ['observed', 'daily', 'forecast'])->whereBetween('period_start', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])->get();
        $byDay = $rows->groupBy(fn ($r) => Carbon::parse($r->getRawOriginal('period_start'), 'UTC')->toDateString());
        $lat = $farm->latitude !== null ? (float) $farm->latitude : -26.0;
        $out = [];
        $last = null;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $g = $byDay[$key] ?? collect();
            $obs = $g->firstWhere('kind', 'observed');
            $daily = $g->firstWhere('kind', 'daily');
            $hours = $g->where('kind', 'forecast');
            [$et0, $source] = match (true) {
                $obs?->et0_mm !== null => [$obs->et0_mm, 'provider'],
                $daily?->et0_mm !== null => [$daily->et0_mm, 'provider'],
                $obs?->temp_min !== null && $obs?->temp_max !== null => [self::hargreaves($obs->temp_min, $obs->temp_max, $lat, $d->dayOfYear), 'hargreaves'],
                $daily?->temp_min !== null && $daily?->temp_max !== null => [self::hargreaves($daily->temp_min, $daily->temp_max, $lat, $d->dayOfYear), 'hargreaves'],
                $hours->whereNotNull('temperature')->count() >= 12 => [self::hargreaves($hours->min('temperature'), $hours->max('temperature'), $lat, $d->dayOfYear), 'hargreaves'],
                $last !== null => [$last, 'carried'],
                default => [null, null],
            };
            $rain = $obs?->rain_mm ?? $daily?->rain_mm ?? ($hours->isNotEmpty() ? (float) $hours->sum('rain_mm') : 0.0);
            $out[$key] = ['et0' => $et0 !== null ? round((float) $et0, 2) : null, 'source' => $source, 'rain' => round((float) $rain, 2)];
            if ($et0 !== null) {
                $last = $et0;
            }
        }

        return $out;
    }

    /** One zone or field: history, forecast and its recommendations. Null when nothing grows there. */
    public function computeScope(Farm $farm, array $scope, array $weather, Carbon $today): ?array
    {
        /** @var FarmField $field */
        $field = $scope['field'];
        $zone = $scope['zone'];
        $from = $today->copy()->subDays(self::HISTORY_DAYS);
        $to = $today->copy()->addDays(self::FORECAST_DAYS - 1);
        $plans = CropPlan::withoutGlobalScopes()->where('field_id', $field->id)->where('intercrop', false)
            ->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING, CropPlan::COMPLETED])
            ->whereDate('planned_planting_on', '<=', $to)->get()
            ->filter(fn (CropPlan $p) => ($p->harvest_completed_on ?? $p->planned_harvest_on)->gte($from))->sortBy('planned_planting_on')->values();
        if ($plans->isEmpty()) {
            WaterBalanceDay::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->where('day', '>=', $today)->delete();
            $this->replaceRecommendations($scope, [], $today);

            return null;
        }
        $coeff = CropCoefficient::withoutGlobalScopes()->where('company_id', $farm->company_id)->whereIn('crop_type_id', $plans->pluck('crop_type_id'))->get()->keyBy('crop_type_id');
        $stages = CropStageEvent::withoutGlobalScopes()->whereIn('crop_plan_id', $plans->pluck('id'))->orderBy('observed_on')->get()->groupBy('crop_plan_id');
        $perennial = CropType::withoutGlobalScopes()->whereIn('id', $plans->pluck('crop_type_id'))->whereIn('category', ['fruit', 'nut', 'sugar'])->pluck('id')->all();
        [$fc, $pwp] = self::soil($field->soil_type);
        $given = $this->irrigationByDay($scope, $from, $to);
        $sensor = $this->sensorByDay($scope, $from, $today);

        // Carry on from yesterday's stored balance where there is one, so a shorter window keeps its history.
        $prev = WaterBalanceDay::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->where('day', $from->copy()->subDay())->where('is_forecast', false)->first();
        $dr = $prev?->depletion_mm ?? 0.0;
        $rows = [];
        $recs = [];
        $planId = $prev?->crop_plan_id;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $p = $plans->first(fn (CropPlan $x) => ($x->planted_on ?? $x->planned_planting_on)->lte($d) && ($x->harvest_completed_on ?? $x->planned_harvest_on)->gte($d));
            $c = $p ? ($coeff[$p->crop_type_id] ?? null) : null;
            if (! $p || ! $c) {
                $planId = null;
                continue;
            }
            if ((int) $p->id !== (int) $planId) {
                $dr = 0.0;                 // a new crop starts at field capacity (pre-irrigated or after rain)
                $planId = $p->id;
            }
            $start = ($p->planted_on ?? $p->planned_planting_on)->copy();
            $end = ($p->harvest_completed_on ?? $p->planned_harvest_on)->copy();
            $stage = $stages[$p->id] ?? collect();
            $seen = $stage->filter(fn ($s) => $s->observed_on->lte($d))->last()?->stage;
            $cd = $this->cropDay($c, $start, $end, $d, $seen, in_array($p->crop_type_id, $perennial, true));
            if (! $cd) {
                continue;
            }
            $w = $weather[$key] ?? ['et0' => null, 'source' => null, 'rain' => 0.0];
            $taw = round(($fc - $pwp) * 10 * $cd['root_m'], 2);
            $reset = false;
            $vwc = $sensor[$key] ?? null;
            if ($vwc !== null) {
                $dr = max(0.0, min($taw, ($fc - $vwc) * 10 * $cd['root_m']));
                $reset = true;
            }
            $etc = $w['et0'] !== null ? round($w['et0'] * $cd['kc'], 2) : 0.0;
            $pAdj = max(0.1, min(0.8, $c->depletion_p + 0.04 * (5 - $etc)));
            $raw = round($pAdj * $taw, 2);
            $eff = self::effectiveRain($w['rain']);
            $irr = round(($given[$key] ?? 0.0) * $scope['efficiency'], 2);
            $forecast = $d->gte($today);

            // A forecast day that would reach the threshold with nothing planned: recommend refilling it.
            $drIfNothing = max(0.0, min($taw, $dr - $eff - $irr + $etc));
            if ($forecast && $irr <= 0 && $drIfNothing >= $raw) {
                $net = round($drIfNothing, 1);
                $gross = round($net / $scope['efficiency'], 1);
                $recs[] = ['day' => $key, 'depletion' => round($drIfNothing, 1), 'raw' => $raw, 'net' => $net, 'gross' => $gross,
                    'm3' => $scope['area_ha'] ? round($gross * $scope['area_ha'] * 10, 1) : null, 'plan_id' => $p->id,
                    'reason' => $d->equalTo($today) && $dr >= $raw
                        ? sprintf('Past the allowed depletion: %s mm of %s mm readily available water used.', round($dr, 1), round($raw, 1))
                        : sprintf('Depletion reaches %s mm against %s mm readily available water (ETc %s mm/day).', round($drIfNothing, 1), round($raw, 1), $etc)];
                $irr = round($net, 2);       // the rest of the week assumes it is given
            }
            $dr = max(0.0, min($taw, $dr - $eff - $irr + $etc));
            $rows[] = ['day' => $key, 'is_forecast' => $forecast, 'et0_mm' => $w['et0'], 'et0_source' => $w['source'], 'kc' => $cd['kc'], 'etc_mm' => $etc,
                'rain_mm' => $w['rain'], 'effective_rain_mm' => $eff, 'irrigation_mm' => $irr, 'depletion_mm' => round($dr, 2), 'taw_mm' => $taw, 'raw_mm' => $raw,
                'reset_by_sensor' => $reset, 'sensor_vwc_pct' => $vwc, 'crop_plan_id' => $p->id];
        }
        $this->store($farm, $scope, $rows, $from, $to);
        $made = $this->replaceRecommendations($scope, $recs, $today);

        return ['days' => count($rows), 'recommendations' => $made, 'rows' => $rows, 'recs' => $recs];
    }

    /** Irrigation given (and, from today, planned) on the zone, in gross mm over its area, by day. */
    private function irrigationByDay(array $scope, Carbon $from, Carbon $to): array
    {
        $field = $scope['field'];
        $zone = $scope['zone'];
        $fieldHa = $field->hectares() ?: null;
        $events = IrrigationEvent::withoutGlobalScope('farm_access')->where('field_id', $field->id)->whereIn('status', ['done', 'planned'])
            ->where(fn ($q) => $zone ? $q->where('zone_id', $zone->id)->orWhereNull('zone_id') : $q)
            ->where(fn ($q) => $q->whereBetween('actual_start', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->orWhereBetween('planned_start', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]))->get();
        $out = [];
        foreach ($events as $e) {
            if ($e->status === 'done') {
                $at = $e->actual_start ?? $e->planned_start;
                $m3 = (float) $e->actual_m3;
            } else {
                $at = $e->planned_start;
                if (! $at || $at->copy()->startOfDay()->lt(today())) {
                    continue;              // a plan left in the past was never given
                }
                $flow = $e->zone?->flow_m3_per_hour;
                $m3 = (float) ($e->planned_m3 ?? ($flow && $e->planned_minutes ? $flow * $e->planned_minutes / 60 : 0));
            }
            $ha = $e->zone_id ? ($scope['area_ha'] ?? $fieldHa) : $fieldHa;
            if (! $at || $m3 <= 0 || ! $ha) {
                continue;
            }
            $key = Carbon::parse($at)->toDateString();
            $out[$key] = ($out[$key] ?? 0.0) + $m3 / ($ha * 10);
        }

        return $out;
    }

    /** The day's last soil-moisture reading on the zone (or anywhere on the field), by day. */
    private function sensorByDay(array $scope, Carbon $from, Carbon $to): array
    {
        $zone = $scope['zone'];

        return SoilMoistureReading::withoutGlobalScope('farm_access')->where('field_id', $scope['field']->id)
            ->when($zone, fn ($q) => $q->where(fn ($q) => $q->where('zone_id', $zone->id)->orWhereNull('zone_id')))
            ->whereBetween('read_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderBy('read_at')->get()
            ->groupBy(fn ($r) => $r->read_at->toDateString())->map(fn ($g) => (float) $g->last()->vwc_pct)->all();
    }

    private function store(Farm $farm, array $scope, array $rows, Carbon $from, Carbon $to): void
    {
        DB::transaction(function () use ($farm, $scope, $rows, $from, $to) {
            WaterBalanceDay::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->whereBetween('day', [$from->toDateString(), $to->toDateString()])
                ->whereNotIn('day', array_column($rows, 'day'))->delete();
            foreach ($rows as $r) {
                WaterBalanceDay::withoutGlobalScope('farm_access')->updateOrCreate(['scope_key' => $scope['key'], 'day' => $r['day']],
                    $r + ['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $scope['field']->id, 'zone_id' => $scope['zone']?->id]);
            }
        });
    }

    /** Open recommendations are replaced by today's; planned or dismissed ones stay as decided. */
    private function replaceRecommendations(array $scope, array $recs, Carbon $today): int
    {
        $field = $scope['field'];
        $kept = IrrigationRecommendation::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])
            ->whereIn('status', [IrrigationRecommendation::PLANNED, IrrigationRecommendation::DISMISSED])->where('recommend_on', '>=', $today)
            ->get()->map(fn ($r) => $r->recommend_on->toDateString())->all();
        // An open recommendation for the same day is brought up to date (so a screen showing it can
        // still act on it); open ones for days no longer recommended are superseded.
        $open = IrrigationRecommendation::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->where('status', IrrigationRecommendation::OPEN)->get()
            ->keyBy(fn ($r) => $r->recommend_on->toDateString());
        $n = 0;
        $touched = [];
        foreach ($recs as $r) {
            if (in_array($r['day'], $kept, true)) {
                continue;
            }
            $row = ['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'zone_id' => $scope['zone']?->id, 'scope_key' => $scope['key'],
                'crop_plan_id' => $r['plan_id'], 'recommend_on' => $r['day'], 'depletion_mm' => $r['depletion'], 'raw_mm' => $r['raw'], 'net_mm' => $r['net'], 'gross_mm' => $r['gross'],
                'volume_m3' => $r['m3'], 'area_ha' => $scope['area_ha'], 'reason' => mb_strimwidth($r['reason'], 0, 300), 'status' => IrrigationRecommendation::OPEN, 'computed_at' => now()];
            if ($existing = $open[$r['day']] ?? null) {
                $existing->update($row);
                $touched[] = $existing->id;
            } else {
                $touched[] = IrrigationRecommendation::withoutGlobalScope('farm_access')->create($row)->id;
            }
            $n++;
        }
        IrrigationRecommendation::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->where('status', IrrigationRecommendation::OPEN)
            ->whereNotIn('id', $touched ?: [0])->update(['status' => IrrigationRecommendation::SUPERSEDED, 'updated_at' => now()]);
        // Superseded advice older than a month is of no further use.
        IrrigationRecommendation::withoutGlobalScope('farm_access')->where('scope_key', $scope['key'])->where('status', IrrigationRecommendation::SUPERSEDED)
            ->where('updated_at', '<', now()->subDays(30))->delete();

        return $n;
    }

    // ── Decisions ───────────────────────────────────────────────────────────────────────────

    /** Turn a recommendation into a planned irrigation event (at 05:00 on its day unless a start is given). */
    public function plan(IrrigationRecommendation $rec, User $actor, ?string $start = null): IrrigationEvent
    {
        if ($rec->status !== IrrigationRecommendation::OPEN) {
            throw ValidationException::withMessages(['recommendation' => 'That recommendation has already been '.$rec->status.'.']);
        }
        $field = FarmField::query()->findOrFail($rec->field_id);
        $zone = $rec->zone_id ? IrrigationZone::query()->find($rec->zone_id) : null;
        $m3 = $rec->volume_m3;
        $minutes = $zone?->flow_m3_per_hour && $m3 ? (int) min(2880, max(1, round($m3 / $zone->flow_m3_per_hour * 60))) : null;
        if (! $m3 && ! $minutes) {
            throw ValidationException::withMessages(['recommendation' => 'Give the zone or field an area so the volume can be worked out.']);
        }

        return DB::transaction(function () use ($rec, $actor, $start, $field, $zone, $m3, $minutes) {
            $event = $this->irrigation->plan($field, ['zone_id' => $zone?->id, 'planned_start' => $start ? Carbon::parse($start) : $rec->recommend_on->copy()->setTime(5, 0),
                'planned_minutes' => $minutes, 'planned_m3' => $m3,
                'notes' => mb_strimwidth("From the water balance: {$rec->gross_mm} mm gross ({$rec->net_mm} mm net). {$rec->reason}", 0, 500)], $actor);
            $rec->update(['status' => IrrigationRecommendation::PLANNED, 'event_id' => $event->id, 'decided_by' => $actor->id]);
            Cache::forget("agri_water_balance:{$field->farm_id}");

            return $event;
        });
    }

    public function dismiss(IrrigationRecommendation $rec, User $actor): IrrigationRecommendation
    {
        if ($rec->status !== IrrigationRecommendation::OPEN) {
            throw ValidationException::withMessages(['recommendation' => 'That recommendation has already been '.$rec->status.'.']);
        }
        $rec->update(['status' => IrrigationRecommendation::DISMISSED, 'decided_by' => $actor->id]);

        return $rec;
    }

    // ── Alerts and screens ──────────────────────────────────────────────────────────────────

    /**
     * Zones past their allowed depletion (on the last recorded day) with no irrigation planned in the
     * next two days. Read by the farm alert scan.
     *
     * @return list<array{scope: string, field: string, zone: ?string, depletion: float, raw: float, taw: float, on: string}>
     */
    public function pastThreshold(Farm $farm, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $rows = WaterBalanceDay::withoutGlobalScope('farm_access')->where('farm_id', $farm->id)
            ->whereIn('day', [$today->toDateString(), $today->copy()->subDay()->toDateString()])->orderBy('day')->get()->groupBy('scope_key');
        $out = [];
        foreach ($rows as $key => $g) {
            $r = $g->firstWhere(fn ($x) => $x->day->lt($today)) ?? $g->first();
            if ($r->depletion_mm < $r->raw_mm) {
                continue;
            }
            $planned = IrrigationEvent::withoutGlobalScope('farm_access')->where('field_id', $r->field_id)->where('status', 'planned')
                ->when($r->zone_id, fn ($q) => $q->where(fn ($q) => $q->where('zone_id', $r->zone_id)->orWhereNull('zone_id')))
                ->whereBetween('planned_start', [$today->copy()->startOfDay(), $today->copy()->addDays(2)->endOfDay()])->exists();
            if ($planned) {
                continue;
            }
            $out[] = ['scope' => $key, 'field' => (string) FarmField::withoutGlobalScopes()->whereKey($r->field_id)->value('code'),
                'zone' => $r->zone_id ? IrrigationZone::withoutGlobalScopes()->whereKey($r->zone_id)->value('code') : null,
                'depletion' => $r->depletion_mm, 'raw' => $r->raw_mm, 'taw' => $r->taw_mm, 'on' => $r->day->toDateString()];
        }

        return $out;
    }

    /** The irrigation screen's balance: a chart per zone and the week's schedule. */
    public function screen(?Farm $farm, ?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $rows = WaterBalanceDay::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->where('day', '>=', $today->copy()->subDays(30))
            ->orderBy('day')->get()->groupBy('scope_key');
        $zones = IrrigationZone::query()->whereIn('id', $rows->flatten()->pluck('zone_id')->filter()->unique())->get()->keyBy('id');
        $fields = FarmField::query()->whereIn('id', $rows->flatten()->pluck('field_id')->unique())->get()->keyBy('id');
        $plans = CropPlan::query()->whereIn('id', $rows->flatten()->pluck('crop_plan_id')->filter()->unique())->with('cropType:id,name')->get()->keyBy('id');
        $recs = IrrigationRecommendation::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereIn('status', [IrrigationRecommendation::OPEN, IrrigationRecommendation::PLANNED])
            ->whereBetween('recommend_on', [$today->toDateString(), $today->copy()->addDays(self::FORECAST_DAYS - 1)->toDateString()])->orderBy('recommend_on')->get();
        $label = fn ($fieldId, $zoneId) => trim(($fields[$fieldId]->code ?? '').($zoneId && isset($zones[$zoneId]) ? ' · '.$zones[$zoneId]->code : ''));

        return [
            'balances' => $rows->map(function (Collection $g, $key) use ($label, $plans, $today) {
                $last = $g->last(fn ($r) => $r->day->lt($today)) ?? $g->first();
                $plan = $plans[$g->last()->crop_plan_id] ?? null;

                return ['scope' => $key, 'label' => $label($g->first()->field_id, $g->first()->zone_id), 'crop' => $plan?->cropType?->name, 'plan' => $plan?->code,
                    'depletion' => $last->depletion_mm, 'raw' => $last->raw_mm, 'taw' => $last->taw_mm, 'past' => $last->depletion_mm >= $last->raw_mm,
                    'points' => $g->map(fn ($r) => ['day' => $r->day->toDateString(), 'forecast' => $r->is_forecast, 'depletion' => $r->depletion_mm, 'raw' => $r->raw_mm,
                        'taw' => $r->taw_mm, 'etc' => $r->etc_mm, 'et0' => $r->et0_mm, 'et0_source' => $r->et0_source, 'kc' => $r->kc, 'rain' => $r->rain_mm,
                        'irrigation' => $r->irrigation_mm, 'sensor' => $r->reset_by_sensor])->values()];
            })->sortByDesc(fn ($b) => $b['taw'] > 0 ? $b['depletion'] / $b['taw'] : 0)->values(),
            'schedule' => $recs->map(fn (IrrigationRecommendation $r) => ['id' => $r->uuid, 'on' => $r->recommend_on->toDateString(), 'label' => $label($r->field_id, $r->zone_id),
                'net_mm' => $r->net_mm, 'gross_mm' => $r->gross_mm, 'm3' => $r->volume_m3, 'depletion' => $r->depletion_mm, 'raw' => $r->raw_mm,
                'reason' => $r->reason, 'status' => $r->status])->values(),
            'coefficients' => CropCoefficient::query()->with('cropType:id,name,code')->get()->sortBy(fn ($c) => $c->cropType?->name)->map(fn ($c) => [
                'id' => $c->uuid, 'crop' => $c->cropType?->name, 'kc_ini' => $c->kc_ini, 'kc_mid' => $c->kc_mid, 'kc_end' => $c->kc_end,
                'stage_ini_pct' => $c->stage_ini_pct, 'stage_dev_pct' => $c->stage_dev_pct, 'stage_mid_pct' => $c->stage_mid_pct, 'stage_late_pct' => $c->stage_late_pct,
                'root_depth_m' => $c->root_depth_m, 'depletion_p' => $c->depletion_p, 'source' => $c->source])->values(),
        ];
    }
}
