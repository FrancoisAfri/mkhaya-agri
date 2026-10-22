<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\MarketRestriction;
use App\Modules\Agri\Models\MrlLimit;
use App\Modules\Agri\Models\MrlMarket;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\ResidueTest;
use App\Modules\Agri\Services\ResidueControls;
use App\Modules\Agri\Support\MrlStarterSet;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Residues and MRLs: lot status per market, residue tests, the pre-harvest risk list, the MRL table
 * (with the starter set and CSV import), banned and restricted actives, the markets — and the
 * override that lets a blocked load go.
 */
class ResidueController extends AgriController
{
    public const TABS = ['lots', 'tests', 'risks', 'limits', 'restrictions', 'markets'];

    public function __construct(private readonly ResidueControls $residues) {}

    public function index(Request $request, string $tab = 'lots'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_CROPS);
        $companyId = (int) $request->user()->company_id;
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $markets = $this->residues->markets($companyId, false);
        $fieldCodes = $farm ? FarmField::query()->where('farm_id', $farm->id)->pluck('code', 'id') : collect();

        $data = match ($tab) {
            'lots' => ['lots' => $farm ? ProduceLot::query()->where('farm_id', $farm->id)->whereIn('status', ['available', 'quarantined', 'dispatched'])
                ->orderByDesc('produced_on')->orderByDesc('id')->limit(100)->get()->map(fn ($l) => ['id' => $l->uuid, 'code' => $l->code, 'product' => $l->product, 'grade' => $l->grade,
                    'kind' => $l->kind, 'status' => $l->status, 'produced_on' => $l->produced_on?->toDateString(), 'target_markets' => $l->target_markets ?? [],
                    'residue' => $this->residues->assess($l)])->values() : []],
            'tests' => ['tests' => $farm ? ResidueTest::query()->where('farm_id', $farm->id)->with('results')->orderByDesc('sampled_on')->limit(100)->get()
                ->map(function ($t) use ($fieldCodes) {
                    $lot = $t->produce_lot_id ? ProduceLot::query()->find($t->produce_lot_id) : null;
                    $plan = $t->crop_plan_id ? CropPlan::query()->find($t->crop_plan_id) : null;
                    $markets = $lot ? $this->residues->marketsFor($lot) : ($plan ? $this->residues->marketsForPlan($plan) : []);

                    return ['id' => $t->uuid, 'reference' => $t->reference, 'laboratory' => $t->laboratory, 'lab_reference' => $t->lab_reference, 'sample_type' => $t->sample_type,
                        'lot' => $lot?->code, 'field' => $fieldCodes[$t->field_id] ?? null, 'plan' => $plan?->code, 'crop_code' => $t->crop_code,
                        'sampled_on' => $t->sampled_on?->toDateString(), 'reported_on' => $t->reported_on?->toDateString(), 'certificate' => $t->certificate_name,
                        'markets' => $markets, 'results' => $this->residues->compare($t, $markets, $lot ? $this->residues->origins($lot)['crop_code'] : null)];
                })->values() : []],
            'risks' => ['risks' => $farm ? $this->residues->preHarvestRisks($farm) : [],
                'crop_markets' => $farm ? CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING])
                    ->orderBy('code')->get(['uuid', 'code', 'target_markets'])->map(fn ($p) => ['id' => $p->uuid, 'code' => $p->code, 'target_markets' => $p->target_markets ?? []])->values() : []],
            'limits' => ['limits' => MrlLimit::query()->when($request->query('crop'), fn ($q, $c) => $q->where('crop_code', $c))
                ->when($request->query('market'), fn ($q, $m) => $q->where('market_code', $m))
                ->when($request->query('q'), fn ($q, $s) => $q->where('active_key', 'like', '%'.ResidueControls::key($s).'%'))
                ->orderBy('crop_code')->orderBy('active_key')->orderBy('market_code')->limit(500)->get()
                ->map(fn ($m) => ['id' => $m->uuid, 'active_ingredient' => $m->active_ingredient, 'crop_code' => $m->crop_code, 'market_code' => $m->market_code,
                    'mrl_mg_kg' => $m->mrl_mg_kg, 'source' => $m->source, 'effective_from' => $m->effective_from?->toDateString(), 'is_starter' => $m->is_starter, 'notes' => $m->notes])->values(),
                'starter_installed' => MrlLimit::query()->where('is_starter', true)->exists()],
            'restrictions' => ['restrictions' => MarketRestriction::query()->orderBy('market_code')->orderBy('active_key')->get()
                ->map(fn ($r) => ['id' => $r->uuid, 'active_ingredient' => $r->active_ingredient, 'market_code' => $r->market_code, 'status' => $r->status, 'source' => $r->source,
                    'effective_from' => $r->effective_from?->toDateString(), 'is_starter' => $r->is_starter, 'notes' => $r->notes])->values()],
            'markets' => [],
        };

        return Inertia::render('Modules/Agri/Residues/Index', [
            'tab' => $tab, 'data' => $data,
            'filters' => ['farm' => $farm?->uuid, 'crop' => $request->query('crop'), 'market' => $request->query('market'), 'q' => $request->query('q')],
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'markets' => $markets->map(fn ($m) => ['id' => $m->uuid, 'code' => $m->code, 'name' => $m->name, 'countries' => $m->countries ?? [], 'default_mrl_mg_kg' => $m->default_mrl_mg_kg,
                'requires_result' => $m->requires_result, 'default_target' => $m->default_target, 'is_active' => $m->is_active, 'is_system' => $m->is_system, 'notes' => $m->notes])->values(),
            'options' => [
                'crops' => CropType::query()->orderBy('name')->get(['code', 'name'])->map(fn ($c) => ['value' => $c->code, 'title' => $c->name])->values(),
                'lots' => $farm ? ProduceLot::query()->where('farm_id', $farm->id)->orderByDesc('id')->limit(200)->get(['id', 'code', 'product'])->map(fn ($l) => ['value' => $l->id, 'title' => "{$l->code} — {$l->product}"])->values() : [],
                'fields' => $fieldCodes->map(fn ($code, $id) => ['value' => $id, 'title' => $code])->values(),
                'plans' => $farm ? CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', [CropPlan::APPROVED, CropPlan::PLANTED, CropPlan::HARVESTING, CropPlan::COMPLETED])
                    ->orderByDesc('id')->limit(100)->get(['id', 'code', 'target_markets'])->map(fn ($p) => ['value' => $p->id, 'title' => $p->code, 'target_markets' => $p->target_markets ?? []])->values() : [],
                'statuses' => MarketRestriction::STATUSES,
                'starter_source' => MrlStarterSet::SOURCE,
            ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_RESIDUES), 'override' => $request->user()->can(R::OVERRIDE_RESIDUES)],
        ]);
    }

    public function storeTest(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $data = $request->validate([
            'farm_id' => ['required', 'string'], 'laboratory' => ['required', 'string', 'max:160'], 'lab_reference' => ['nullable', 'string', 'max:80'],
            'sample_type' => ['required', Rule::in(ResidueTest::SAMPLE_TYPES)], 'produce_lot_id' => ['nullable', 'integer'], 'field_id' => ['nullable', 'integer'],
            'crop_plan_id' => ['nullable', 'integer'], 'crop_code' => ['nullable', 'string', 'max:40'], 'sampled_on' => ['required', 'date', 'before_or_equal:today'],
            'reported_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000'],
            'results' => ['required', 'array', 'min:1'], 'results.*.active_ingredient' => ['required', 'string', 'max:160'],
            'results.*.measured_mg_kg' => ['nullable', 'numeric', 'gte:0'], 'results.*.loq_mg_kg' => ['nullable', 'numeric', 'gte:0'], 'results.*.below_loq' => ['nullable', 'boolean'],
            'certificate' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        if (! empty($data['field_id'])) {
            $this->fieldOn($farm, FarmField::query()->whereKey($data['field_id'])->value('uuid'));
        }
        $file = $request->file('certificate');
        $cert = $file ? ['name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'data' => base64_encode((string) file_get_contents($file->getRealPath()))] : null;
        $test = $this->residues->recordTest($farm, collect($data)->except(['results', 'certificate', 'farm_id'])->all(), $data['results'], $cert, $request->user());

        return back()->with('success', "Residue test {$test->reference} recorded.");
    }

    public function certificate(Request $request, ResidueTest $test)
    {
        $this->allow($request, R::VIEW_CROPS);
        abort_unless($test->certificate_data, 404);

        return response(base64_decode($test->certificate_data), 200, ['Content-Type' => $test->certificate_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($test->certificate_name ?: 'certificate').'"']);
    }

    public function lotMarkets(Request $request, ProduceLot $lot): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $this->assertWritable($request, $lot->farm_id);
        $data = $request->validate(['markets' => ['present', 'array'], 'markets.*' => ['string', 'max:20']]);
        $lot->update(['target_markets' => $this->validMarkets($request, $data['markets'])]);

        return back()->with('success', "Target markets of lot {$lot->code} saved.");
    }

    public function planMarkets(Request $request, CropPlan $plan): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES, R::PLAN_CROPS);
        $this->assertWritable($request, $plan->farm_id);
        $data = $request->validate(['markets' => ['present', 'array'], 'markets.*' => ['string', 'max:20']]);
        $plan->update(['target_markets' => $this->validMarkets($request, $data['markets'])]);

        return back()->with('success', "Crop {$plan->code}'s target markets saved.");
    }

    private function validMarkets(Request $request, array $codes): ?array
    {
        $known = $this->residues->markets((int) $request->user()->company_id, false)->keys();
        $codes = collect($codes)->map(fn ($c) => strtoupper($c))->unique()->values();
        abort_unless($codes->diff($known)->isEmpty(), 422, 'Unknown market.');

        return $codes->isEmpty() ? null : $codes->all();
    }

    public function saveLimit(Request $request, ?MrlLimit $limit = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $data = $request->validate(['active_ingredient' => ['required', 'string', 'max:160'], 'crop_code' => ['required', 'string', 'max:40'], 'market_code' => ['required', 'string', 'max:20'],
            'mrl_mg_kg' => ['required', 'numeric', 'gte:0'], 'source' => ['nullable', 'string', 'max:300'], 'effective_from' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->residues->saveLimit((int) $request->user()->company_id, $data, $request->user(), $limit);

        return back()->with('success', 'MRL saved.');
    }

    public function deleteLimit(Request $request, MrlLimit $limit): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $limit->delete();

        return back()->with('success', 'MRL removed.');
    }

    public function starter(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $n = $this->residues->installStarterSet((int) $request->user()->company_id, $request->user());

        return back()->with('success', "Starter set installed: {$n['limits']} limits and {$n['restrictions']} restrictions. Verify each against the official database.");
    }

    public function import(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        $n = $this->residues->import((int) $request->user()->company_id, (string) file_get_contents($request->file('file')->getRealPath()), $request->user());

        return back()->with('success', "Imported: {$n['created']} new limits, {$n['updated']} updated.");
    }

    public function saveRestriction(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $data = $request->validate(['active_ingredient' => ['required', 'string', 'max:160'], 'market_code' => ['required', 'string', 'max:20'],
            'status' => ['required', Rule::in(MarketRestriction::STATUSES)], 'source' => ['nullable', 'string', 'max:300'], 'effective_from' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->residues->saveRestriction((int) $request->user()->company_id, $data);

        return back()->with('success', 'Restriction saved.');
    }

    public function deleteRestriction(Request $request, MarketRestriction $restriction): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $restriction->delete();

        return back()->with('success', 'Restriction removed.');
    }

    public function saveMarket(Request $request, ?MrlMarket $market = null): RedirectResponse
    {
        $this->allow($request, R::MANAGE_RESIDUES);
        $data = $request->validate(['code' => [$market ? 'nullable' : 'required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'],
            'countries' => ['nullable', 'array'], 'countries.*' => ['string', 'max:60'], 'default_mrl_mg_kg' => ['nullable', 'numeric', 'gte:0'],
            'requires_result' => ['boolean'], 'default_target' => ['boolean'], 'is_active' => ['boolean'], 'notes' => ['nullable', 'string', 'max:500']]);
        $this->residues->saveMarket((int) $request->user()->company_id, $data, $market);

        return back()->with('success', 'Market saved.');
    }

    /** Release a load the residue check stopped, with a reason — audited. */
    public function override(Request $request, string $dispatch): RedirectResponse
    {
        $this->allow($request, R::OVERRIDE_RESIDUES);
        $load = FulfilmentOrder::query()->where('uuid', $dispatch)->firstOrFail();
        $farm = app(\App\Modules\Agri\Services\ExportDocuments::class)->farmOf($load) ?? abort(404);
        abort_unless(Farm::query()->whereKey($farm->id)->exists(), 404);
        $this->assertWritable($request, $farm->id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $o = $this->residues->override($load, $data['reason'], $request->user());

        return back()->with('success', "Residue check overridden for {$o->market_code}; the reason is on record.");
    }
}
