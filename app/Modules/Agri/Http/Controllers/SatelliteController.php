<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FieldImagery;
use App\Modules\Agri\Models\SatelliteProvider;
use App\Modules\Agri\Satellite\CopernicusProvider;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\SatelliteImagery;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Satellite crop health: the farm's fields by their latest NDVI change, a field's NDVI over time
 * with its zones, the NDVI pictures for the field map, "fetch now", and the provider settings.
 *
 * Reading needs the crop view permission; fetching needs to record field work (or manage
 * integrations) on a farm the person may write to; the provider and its credentials are set by
 * whoever manages integrations. Credentials never leave the server.
 */
class SatelliteController extends AgriController
{
    public function __construct(private readonly SatelliteImagery $satellite) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_CROPS);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $companyId = (int) $request->user()->company_id;
        $p = SatelliteProvider::activeFor($companyId);
        $health = $farm ? $this->satellite->health($farm) : [];
        $field = $farm && $request->query('field') ? FarmField::query()->where('farm_id', $farm->id)->where('uuid', $request->query('field'))->first() : null;
        $field ??= $farm ? FarmField::query()->where('farm_id', $farm->id)->where('uuid', collect($health)->firstWhere('ndvi', '!==', null)['id'] ?? null)->first() : null;
        $u = $request->user();

        return Inertia::render('Modules/Agri/Satellite/Index', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'health' => $health,
            'series' => $field ? $this->satellite->series($field) : null,
            'provider' => $p ? ['key' => $p->provider, 'name' => config("agri.satellite.providers.{$p->provider}.name"), 'ok' => $p->last_test_ok,
                'budget' => (int) $p->options()['daily_budget'], 'remaining' => $this->satellite->budget($p)->remaining(), 'max_cloud_pct' => (float) $p->options()['max_cloud_pct'],
                'watched' => $farm ? $this->satellite->fieldsToFetch($farm, $p)->count() : 0] : null,
            'ramp' => CopernicusProvider::RAMP,
            'can' => ['fetch' => $p && $farm && ($u->can(R::RECORD_ACTIVITIES) || $u->can(R::MANAGE_INTEGRATIONS)) && app(FarmAccess::class)->canWrite($u, $farm->id),
                'configure' => $u->can(R::MANAGE_INTEGRATIONS)],
        ]);
    }

    /** The farm's NDVI pictures and every field's series, for the field map's NDVI layer. */
    public function farmLayers(Request $request, Farm $farm): JsonResponse
    {
        $this->allow($request, R::VIEW_CROPS);
        $fields = FarmField::query()->where('farm_id', $farm->id)->where('status', 'active')->whereNotNull('boundary')->orderBy('code')->get();

        return response()->json([
            'layers' => collect($this->satellite->layers($farm))->map(fn ($l) => $l + ['url' => route('agri.satellite.image', $l['id'])])->values(),
            'series' => $fields->mapWithKeys(fn ($f) => [$f->uuid => $this->satellite->series($f)]),
            'ramp' => CopernicusProvider::RAMP,
        ]);
    }

    /** One NDVI picture, as the PNG it was stored as. */
    public function image(Request $request, FieldImagery $imagery)
    {
        $this->allow($request, R::VIEW_CROPS);
        $row = FieldImagery::query()->whereKey($imagery->id)->first(['id', 'image_mime', 'image_data']);
        abort_unless($row && $row->image_mime === 'image/png' && $row->image_data, 404);

        return response(base64_decode($row->image_data), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** "Fetch now" for a farm, or one field on it, within today's budget. */
    public function fetch(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ACTIVITIES, R::MANAGE_INTEGRATIONS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'field_id' => ['nullable', 'string']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $r = $this->satellite->fetchFarm($farm, 'manual:'.now()->format('Y-m-d\TH:i'), $field);

        $msg = "Looked at {$r['fields']} field(s): {$r['scenes']} new scene(s) stored, {$r['cloudy']} too cloudy".($r['flags'] ? ", {$r['flags']} flag(s) raised — scouting checks are open" : '').'.';
        if ($r['stopped'] === 'budget') {
            $msg .= " Today's request budget is spent; the rest follows tomorrow.";
        }
        if ($r['failed']) {
            return back()->with('error', $msg.' Failed (waiting for the retry): '.implode('; ', $r['failed']));
        }

        return back()->with($r['flags'] ? 'warning' : 'success', $msg);
    }

    // ── settings ─────────────────────────────────────────────────────────────────────────────

    public function settings(Request $request): Response
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        $companyId = (int) $request->user()->company_id;
        $rows = SatelliteProvider::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('provider');

        return Inertia::render('Modules/Agri/Satellite/Settings', [
            'providers' => collect($this->satellite->catalogue())->map(function ($c) use ($rows) {
                $p = $rows[$c['key']] ?? null;
                $creds = $p?->credentials() ?? [];

                return $c + ['active' => (bool) $p?->is_active, 'configured' => (bool) $creds,
                    // Only whether each secret is set, and the public client id's start — never the secrets.
                    'has' => collect($creds)->map(fn ($v) => filled($v))->all(),
                    'client_id_hint' => isset($creds['client_id']) ? mb_substr($creds['client_id'], 0, 8).'…' : null,
                    'settings' => $p ? $p->options() : SatelliteProvider::DEFAULTS,
                    'last_test' => $p?->last_test_at ? ['at' => $p->last_test_at->format('Y-m-d H:i'), 'ok' => $p->last_test_ok, 'message' => $p->last_test_message] : null];
            })->values(),
            'usage' => ($active = $rows->firstWhere('is_active', true)) ? ['used' => $this->satellite->budget($active)->used(), 'budget' => (int) $active->options()['daily_budget']] : null,
        ]);
    }

    public function save(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        abort_unless(in_array($provider, SatelliteProvider::KEYS, true), 404);
        $data = $request->validate([
            'active' => ['boolean'],
            'client_id' => ['nullable', 'string', 'max:200'], 'client_secret' => ['nullable', 'string', 'max:500'], 'api_key' => ['nullable', 'string', 'max:200'],
            'max_cloud_pct' => ['required', 'numeric', 'between:0,100'], 'daily_budget' => ['required', 'integer', 'between:1,100000'],
            'lookback_days' => ['required', 'integer', 'between:1,365'], 'require_plan' => ['boolean'], 'zone_stats' => ['boolean'], 'images' => ['boolean'],
        ]);
        $creds = $provider === SatelliteProvider::COPERNICUS
            ? ['client_id' => $data['client_id'] ?? null, 'client_secret' => $data['client_secret'] ?? null]
            : ['api_key' => $data['api_key'] ?? null];
        $settings = ['max_cloud_pct' => (float) $data['max_cloud_pct'], 'daily_budget' => (int) $data['daily_budget'], 'lookback_days' => (int) $data['lookback_days'],
            'require_plan' => (bool) ($data['require_plan'] ?? true), 'zone_stats' => (bool) ($data['zone_stats'] ?? true), 'images' => (bool) ($data['images'] ?? true)];
        $p = $this->satellite->configure((int) $request->user()->company_id, $provider, $creds, $settings, (bool) ($data['active'] ?? false), $request->user());
        if ($p->is_active && ! $p->credentials()) {
            return back()->with('warning', 'Saved, but there are no credentials yet: the fetch cannot run until they are entered.');
        }

        return back()->with('success', $p->is_active ? 'Saved. The daily fetch uses this provider.' : 'Saved.');
    }

    public function check(Request $request, string $provider): RedirectResponse
    {
        $this->allow($request, R::MANAGE_INTEGRATIONS);
        abort_unless(in_array($provider, SatelliteProvider::KEYS, true), 404);
        $p = SatelliteProvider::withoutGlobalScopes()->where('company_id', (int) $request->user()->company_id)->where('provider', $provider)->first();
        if (! $p) {
            return back()->with('error', 'Save the credentials first, then check the connection.');
        }
        $r = $this->satellite->check($p);

        return back()->with($r['ok'] ? 'success' : 'error', $r['message']);
    }
}
