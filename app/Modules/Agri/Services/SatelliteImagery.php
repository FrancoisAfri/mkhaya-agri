<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\ImageryFlag;
use App\Modules\Agri\Models\IntegrationEvent;
use App\Modules\Agri\Models\SatelliteProvider;
use App\Modules\Agri\Models\SatelliteScene;
use App\Modules\Agri\Satellite\ImageryProvider;
use App\Modules\Agri\Satellite\SatelliteBudget;
use App\Modules\Agri\Satellite\SatelliteBudgetExhausted;
use App\Modules\Agri\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satellite crop health the farm fetches itself, per field and per management zone.
 *
 * Every day, for each mapped field (by default only those with an approved or growing crop plan),
 * the organisation's chosen provider is asked for the acquisitions since the last one looked at.
 * A clear scene's statistics go through CropImagery::import() like any capture — so a drop raises
 * the same flag and scouting check — with the zones as its cells and the NDVI picture kept with
 * it; a cloudy scene is remembered and skipped. Nothing is asked for twice: a field's window
 * starts after its last scene, and a date already looked at is never stored again.
 *
 * Each field's fetch is one integration message (§14): a failure is kept with its error and
 * retried with back-off, never stopping the run. The organisation's daily request budget is
 * checked before every request; once used up, the fetch stops and continues tomorrow.
 */
class SatelliteImagery
{
    /** Plans whose fields are watched by default: approved, in the ground, being harvested. */
    public const WATCHED_PLANS = [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING];

    public function __construct(private readonly CropImagery $imagery, private readonly Integrations $integrations) {}

    // ── providers ────────────────────────────────────────────────────────────────────────────

    public function catalogue(): array
    {
        return collect(config('agri.satellite.providers', []))->map(fn ($c, $k) => ['key' => $k, 'name' => $c['name'], 'source' => $c['source']])->values()->all();
    }

    public function client(SatelliteProvider $p, ?SatelliteBudget $budget = null): ImageryProvider
    {
        $cfg = config("agri.satellite.providers.{$p->provider}");
        if (! $cfg) {
            throw new \RuntimeException("Unknown satellite provider {$p->provider}.");
        }

        return new $cfg['class']($cfg, $p->credentials(), $budget);
    }

    public function sourceLabel(string $provider): string
    {
        return (string) config("agri.satellite.providers.{$provider}.source", $provider);
    }

    /**
     * Save a provider's credentials and settings. Blank secrets keep what is stored (the screen
     * never receives them back); making one active switches the others off.
     */
    public function configure(int $companyId, string $provider, array $credentials, array $settings, bool $active, User $actor): SatelliteProvider
    {
        if (! array_key_exists($provider, config('agri.satellite.providers', []))) {
            throw ValidationException::withMessages(['provider' => 'Choose a satellite provider.']);
        }

        return DB::transaction(function () use ($companyId, $provider, $credentials, $settings, $active, $actor) {
            $p = SatelliteProvider::withoutGlobalScopes()->firstOrNew(['company_id' => $companyId, 'provider' => $provider]);
            $kept = $p->credentials();
            foreach ($credentials as $k => $v) {
                if (filled($v)) {
                    $kept[$k] = trim((string) $v);
                }
            }
            $p->putCredentials($kept);
            $p->settings = array_intersect_key(array_merge($p->options(), $settings), SatelliteProvider::DEFAULTS);
            $p->is_active = $active;
            $p->updated_by = $actor->id;
            $p->save();
            if ($active) {
                SatelliteProvider::withoutGlobalScopes()->where('company_id', $companyId)->where('id', '!=', $p->id)->update(['is_active' => false]);
            }

            return $p;
        });
    }

    /** "Check connection": one cheap call with the stored credentials; the outcome is kept. */
    public function check(SatelliteProvider $p): array
    {
        $r = $this->client($p)->check();
        $p->forceFill(['last_test_at' => now(), 'last_test_ok' => $r['ok'], 'last_test_message' => mb_strimwidth($r['message'], 0, 500)])->save();

        return $r;
    }

    public function budget(SatelliteProvider $p): SatelliteBudget
    {
        return new SatelliteBudget((int) $p->company_id, (int) $p->options()['daily_budget']);
    }

    // ── fetching ─────────────────────────────────────────────────────────────────────────────

    /** The fields of a farm the fetch looks at: mapped, active, and (by default) with a watched crop plan. */
    public function fieldsToFetch(Farm $farm, ?SatelliteProvider $p = null): Collection
    {
        $requirePlan = (bool) ($p?->options()['require_plan'] ?? true);

        return FarmField::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('farm_id', $farm->id)->where('status', 'active')->whereNotNull('boundary')
            ->when($requirePlan, fn ($q) => $q->whereIn('id', CropPlan::withoutGlobalScopes()->where('farm_id', $farm->id)->whereIn('status', self::WATCHED_PLANS)->select('field_id')))
            ->orderBy('code')->get()->filter(fn ($f) => Geo::polygon($f->boundary) !== null)->values();
    }

    /**
     * Fetch one field through the integration log. $run names the run (the day, or a manual
     * fetch's minute), so the same run is never processed twice and a failure waits for the retry.
     */
    public function fetch(FarmField $field, string $run): IntegrationEvent
    {
        ['event' => $e] = $this->integrations->receive((int) $field->company_id, 'satellite', "field:{$field->id}:{$run}", ['field_id' => $field->id], null, $field->farm_id);

        return $e;
    }

    /** Fetch every watched field on a farm, stopping when the day's budget is spent. */
    public function fetchFarm(Farm $farm, string $run, ?FarmField $only = null): array
    {
        $p = SatelliteProvider::activeFor((int) $farm->company_id);
        if (! $p) {
            throw ValidationException::withMessages(['provider' => 'No satellite provider is switched on. Set one up under Satellite settings.']);
        }
        $fields = $only ? collect([$only]) : $this->fieldsToFetch($farm, $p);
        $out = ['fields' => 0, 'scenes' => 0, 'cloudy' => 0, 'flags' => 0, 'failed' => [], 'stopped' => null];
        foreach ($fields as $f) {
            if ($this->budget($p)->remaining() < 1) {
                $out['stopped'] = 'budget';
                break;
            }
            $e = $this->fetch($f, $run);
            $out['fields']++;
            if ($e->status !== 'processed') {
                $out['failed'][] = "{$f->code}: {$e->error}";

                continue;
            }
            $r = $e->result ?? [];
            $out['scenes'] += (int) ($r['stored'] ?? 0);
            $out['cloudy'] += (int) ($r['cloudy'] ?? 0);
            $out['flags'] += (int) ($r['flags'] ?? 0);
            if (($r['stopped'] ?? null) === 'budget') {
                $out['stopped'] = 'budget';
                break;
            }
        }

        return $out;
    }

    /**
     * The integration handler: one field, every new acquisition since its last. Each scene is
     * stored in its own transaction, so a failure part-way keeps what was stored and the retry
     * carries on from there.
     */
    public function handle(array $payload, IntegrationEvent $e): array
    {
        $field = FarmField::withoutGlobalScopes()->where('company_id', $e->company_id)->findOrFail($payload['field_id'] ?? 0);
        $p = SatelliteProvider::activeFor((int) $e->company_id);
        $geometry = Geo::polygon($field->boundary);
        if (! $p) {
            return ['field' => $field->code, 'skipped' => 'No satellite provider is switched on.'];
        }
        if (! $geometry) {
            return ['field' => $field->code, 'skipped' => 'The field has no boundary.'];
        }
        $o = $p->options();
        $budget = $this->budget($p);
        $zones = $o['zone_stats'] ? FieldZone::withoutGlobalScopes()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)
            ->where('zone_class', '!=', 'exclude')->orderBy('name')->get() : collect();
        $result = ['field' => $field->code, 'provider' => $p->provider, 'stored' => 0, 'cloudy' => 0, 'no_data' => 0, 'flags' => 0, 'images' => 0, 'stopped' => null];

        $known = SatelliteScene::withoutGlobalScopes()->where('field_id', $field->id)->where('provider', $p->provider)->pluck('acquired_on')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $from = today()->subDays(max(1, (int) $o['lookback_days']));
        if ($known) {
            $from = Carbon::parse(max($known))->addDay()->max($from);
        }
        $to = today();
        if ($from->gt($to)) {
            return $result + ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'requests' => 0];
        }
        // Enough left for the field and each zone, or wait for tomorrow rather than spend half a field.
        if ($budget->remaining() < 1 + $zones->count()) {
            return array_merge($result, ['stopped' => 'budget', 'requests' => 0]);
        }
        $client = $this->client($p, $budget);
        try {
            $scenes = $client->scenes($field, $geometry, $zones, $from->toDateString(), $to->toDateString(), (float) $o['max_cloud_pct'], $known);
        } catch (SatelliteBudgetExhausted) {
            return array_merge($result, ['stopped' => 'budget', 'requests' => $budget->spentNow()]);
        }
        usort($scenes, fn ($a, $b) => strcmp($a['date'], $b['date']));
        $source = $this->sourceLabel($p->provider);
        $bounds = Geo::bounds($geometry);
        $zoneRows = $zones->keyBy('uuid');

        foreach ($scenes as $s) {
            if (in_array($s['date'], $known, true) || Carbon::parse($s['date'])->gt($to)) {
                continue;
            }
            if ($s['status'] !== SatelliteScene::STORED || empty($s['indices']['ndvi'])) {
                $status = $s['status'] === SatelliteScene::STORED ? SatelliteScene::NO_DATA : $s['status'];
                SatelliteScene::withoutGlobalScopes()->firstOrCreate(['field_id' => $field->id, 'provider' => $p->provider, 'acquired_on' => $s['date']],
                    ['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'scene_ref' => $s['ref'], 'cloud_pct' => $s['cloud_pct'], 'status' => $status, 'bounds' => $bounds]);
                $result[$status === SatelliteScene::TOO_CLOUDY ? 'cloudy' : 'no_data']++;
                $known[] = $s['date'];

                continue;
            }
            $png = null;
            if ($o['images'] && $result['stopped'] === null) {
                try {
                    $png = $client->image($field, $geometry, $s);
                } catch (SatelliteBudgetExhausted) {
                    $result['stopped'] = 'budget';   // the figures are kept; the picture is not fetched
                }
            }
            $r = DB::transaction(function () use ($field, $s, $source, $png, $bounds, $zoneRows, $p) {
                $cells = fn (string $ix) => collect($s['zones'])->filter(fn ($v) => isset($v[$ix]))->map(function ($v, $uuid) use ($zoneRows, $ix) {
                    $z = $zoneRows[$uuid] ?? null;
                    $c = $z ? Geo::centroid(Geo::polygon($z->boundary)) : null;

                    return ['id' => $uuid, 'name' => $z?->name, 'value' => round((float) $v[$ix], 4), 'lat' => $c[1] ?? null, 'lng' => $c[0] ?? null];
                })->values()->all();
                $main = null;
                foreach ($s['indices'] as $ix => $st) {
                    if (! in_array($ix, FieldImagery::INDICES, true)) {
                        continue;
                    }
                    $data = ['index' => $ix, 'captured_on' => $s['date'], 'source' => $source, 'mean' => $st['mean'], 'min' => $st['min'] ?? null, 'max' => $st['max'] ?? null,
                        'cloud_pct' => $s['cloud_pct'], 'cells' => $cells($ix) ?: null, 'client_id' => mb_strimwidth((string) $s['ref'], 0, 120),
                        // NDVI is what flags; the other indices are kept beside it for the agronomist.
                        'flag' => $ix === 'ndvi'];
                    if ($ix === 'ndvi' && $png) {
                        $data += ['image_mime' => 'image/png', 'image_data' => base64_encode($png)];
                    }
                    $r = $this->imagery->import($field, $data, null);
                    if ($ix === 'ndvi') {
                        $main = $r;
                    }
                }
                SatelliteScene::withoutGlobalScopes()->create(['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id, 'provider' => $p->provider,
                    'acquired_on' => $s['date'], 'scene_ref' => $s['ref'], 'cloud_pct' => $s['cloud_pct'], 'status' => SatelliteScene::STORED,
                    'imagery_id' => $main['imagery']->id, 'bounds' => $bounds, 'zones' => count($s['zones']), 'has_image' => (bool) $png]);

                return $main;
            });
            $known[] = $s['date'];
            $result['stored']++;
            $result['images'] += $png ? 1 : 0;
            $result['flags'] += $r['flag'] ? 1 : 0;
        }

        return $result + ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'requests' => $budget->spentNow()];
    }

    // ── reading ──────────────────────────────────────────────────────────────────────────────

    /**
     * The farm's crop health: each mapped field's latest NDVI and its change from the capture
     * before, the biggest drop first; with the open flags and when the satellite last looked.
     */
    public function health(Farm $farm): array
    {
        $fields = FarmField::query()->where('farm_id', $farm->id)->where('status', 'active')->orderBy('code')->get();
        $ndvi = FieldImagery::query()->where('farm_id', $farm->id)->where('index', 'ndvi')
            ->where(fn ($q) => $q->whereNull('cloud_pct')->orWhere('cloud_pct', '<=', CropImagery::MAX_CLOUD))
            ->orderBy('captured_on')->get(['id', 'uuid', 'field_id', 'captured_on', 'mean', 'change_pct', 'source', 'image_mime'])->groupBy('field_id');
        $flags = ImageryFlag::query()->where('farm_id', $farm->id)->where('validation', 'pending')->selectRaw('field_id, count(*) as n')->groupBy('field_id')->pluck('n', 'field_id');
        $looked = SatelliteScene::query()->where('farm_id', $farm->id)->selectRaw('field_id, max(acquired_on) as d')->groupBy('field_id')->pluck('d', 'field_id');
        $plans = CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', CropPlan::LIVE)->get(['field_id', 'code', 'status'])->keyBy('field_id');

        return $fields->map(function (FarmField $f) use ($ndvi, $flags, $looked, $plans) {
            $rows = $ndvi[$f->id] ?? collect();
            $last = $rows->last();
            $recent = $rows->filter(fn ($r) => $r->captured_on->gte(today()->subDays(30)));

            return ['id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'mapped' => Geo::polygon($f->boundary) !== null,
                'plan' => $plans->get($f->id)?->code, 'latest_on' => $last?->captured_on->toDateString(), 'ndvi' => $last?->mean,
                'change_pct' => $last?->change_pct, 'worst_recent_pct' => $recent->min('change_pct'), 'source' => $last?->source,
                'spark' => $rows->take(-12)->map(fn ($r) => round((float) $r->mean, 3))->values(),
                'open_flags' => (int) ($flags[$f->id] ?? 0), 'last_scene_on' => $looked[$f->id] ?? null];
        })->sort(function ($a, $b) {
            // The biggest recent drop first; fields with no reading at the bottom.
            $x = $a['worst_recent_pct'] ?? $a['change_pct'];
            $y = $b['worst_recent_pct'] ?? $b['change_pct'];

            return [$x === null ? 1 : 0, $x ?? 0, $a['code']] <=> [$y === null ? 1 : 0, $y ?? 0, $b['code']];
        })->values()->all();
    }

    /**
     * A field's NDVI over time: the mean with its min–max band, each zone's mean, and which dates
     * have a picture. Captures too cloudy to trust are left out.
     */
    public function series(FarmField $field): array
    {
        $rows = FieldImagery::query()->where('field_id', $field->id)->where('index', 'ndvi')
            ->where(fn ($q) => $q->whereNull('cloud_pct')->orWhere('cloud_pct', '<=', CropImagery::MAX_CLOUD))
            ->orderBy('captured_on')->get(['id', 'uuid', 'captured_on', 'mean', 'min', 'max', 'cloud_pct', 'change_pct', 'source', 'cells', 'image_mime']);
        $zones = FieldZone::query()->where('field_id', $field->id)->get(['uuid', 'name', 'zone_class', 'status'])->keyBy('uuid');
        $zoneSeries = [];
        foreach ($rows as $r) {
            foreach ((array) $r->cells as $c) {
                if (! isset($c['id'], $c['value'])) {
                    continue;
                }
                $z = $zones[$c['id']] ?? null;
                $zoneSeries[$c['id']] ??= ['id' => $c['id'], 'name' => $z?->name ?? ($c['name'] ?? $c['id']), 'zone_class' => $z?->zone_class, 'points' => []];
                $zoneSeries[$c['id']]['points'][] = ['date' => $r->captured_on->toDateString(), 'value' => (float) $c['value']];
            }
        }

        return [
            'field' => ['id' => $field->uuid, 'code' => $field->code, 'name' => $field->name],
            'points' => $rows->map(fn ($r) => ['id' => $r->uuid, 'date' => $r->captured_on->toDateString(), 'mean' => $r->mean, 'min' => $r->min, 'max' => $r->max,
                'cloud_pct' => $r->cloud_pct, 'change_pct' => $r->change_pct, 'source' => $r->source, 'image' => $r->image_mime === 'image/png'])->values(),
            'zones' => array_values($zoneSeries),
        ];
    }

    /** The NDVI pictures on a farm, newest date first, each with the bounds it covers on the map. */
    public function layers(Farm $farm): array
    {
        $rows = FieldImagery::query()->where('farm_id', $farm->id)->where('index', 'ndvi')->where('image_mime', 'image/png')->whereNotNull('image_data')
            ->orderByDesc('captured_on')->limit(400)->get(['id', 'uuid', 'field_id', 'captured_on', 'mean', 'cloud_pct']);
        $bounds = SatelliteScene::query()->whereIn('imagery_id', $rows->pluck('id'))->pluck('bounds', 'imagery_id');
        $fields = FarmField::query()->whereIn('id', $rows->pluck('field_id')->unique())->get(['id', 'uuid', 'code', 'boundary'])->keyBy('id');

        return $rows->map(function ($r) use ($bounds, $fields) {
            $f = $fields[$r->field_id] ?? null;
            $b = $bounds[$r->id] ?? ($f ? Geo::bounds(Geo::polygon($f->boundary)) : null);

            return $f && $b ? ['id' => $r->uuid, 'date' => $r->captured_on->toDateString(), 'field' => $f->uuid, 'code' => $f->code,
                'mean' => $r->mean, 'cloud_pct' => $r->cloud_pct, 'bounds' => is_string($b) ? json_decode($b, true) : $b] : null;
        })->filter()->values()->all();
    }
}
