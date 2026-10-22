<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\ExportCode;
use App\Modules\Agri\Models\ExportConsignment;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Services\ExportDocuments;
use App\Modules\Agri\Services\ProduceDispatches;
use App\Modules\Agri\Services\ResidueControls;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Export consignments and their document packs: per load, the consignee and transport details,
 * the lots with pallets, cartons and masses, and the documents generated into Document Management;
 * and the farm's exporter registration codes (PUC, PHC, FBO…).
 */
class ExportController extends AgriController
{
    public function __construct(private readonly ExportDocuments $exports, private readonly ResidueControls $residues) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_SALES);
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $loads = $farm ? ProduceDispatches::loadsFor($farm)->orderByDesc('id')->limit(60)->get() : collect();
        $consignments = ExportConsignment::query()->whereIn('wms_order_id', $loads->pluck('id'))->with('lines')->get()->keyBy('wms_order_id');

        return Inertia::render('Modules/Agri/Exports/Index', [
            'filters' => ['farm' => $farm?->uuid],
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'loads' => $loads->map(function (FulfilmentOrder $o) use ($consignments) {
                $c = $consignments[$o->id] ?? null;
                $gate = $this->residues->gate($o);

                return ['id' => $o->uuid, 'number' => $o->number, 'buyer' => $o->customer_name, 'status' => strtolower($o->status), 'country' => $gate['country'],
                    'export' => $this->exports->isExport($o), 'residue' => $gate,
                    'consignment' => $c ? collect($c->toArray())->except(['id', 'company_id', 'farm_id', 'wms_order_id', 'lines', 'created_at', 'updated_at'])->all() + [
                        'departure_on' => $c->departure_on?->toDateString(), 'arrival_on' => $c->arrival_on?->toDateString(), 'inspection_on' => $c->inspection_on?->toDateString(),
                        'lines' => $c->lines->map(fn ($l) => collect($l->toArray())->only(['description', 'hs_code', 'pallet_ids', 'pallets', 'cartons', 'unit', 'net_kg', 'gross_kg',
                            'unit_price', 'variety', 'grade', 'puc'])->all() + ['id' => $l->uuid])->values(),
                        'pack' => $this->exports->packStatus($c)] : null,
                    'refusal' => $this->exports->dispatchRefusal($o)];
            })->values(),
            'codes' => $farm ? ExportCode::query()->where('farm_id', $farm->id)->orderBy('kind')->get()->map(fn ($c) => ['id' => $c->uuid, 'kind' => $c->kind, 'code' => $c->code,
                'store' => $c->store_id ? FarmStore::query()->whereKey($c->store_id)->value('name') : null, 'valid_until' => $c->valid_until?->toDateString(), 'notes' => $c->notes])->values() : [],
            'options' => ['incoterms' => ExportConsignment::INCOTERMS, 'modes' => ExportConsignment::MODES, 'kinds' => ExportDocuments::KINDS, 'code_kinds' => ExportCode::KINDS,
                'stores' => $farm ? FarmStore::query()->where('farm_id', $farm->id)->where('is_active', true)->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'title' => $s->name])->values() : [],
                'home' => ResidueControls::homeCountry()],
            'can' => ['manage' => $request->user()->can(R::MANAGE_EXPORTS)],
        ]);
    }

    private function load(Request $request, string $uuid): FulfilmentOrder
    {
        $load = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();
        $farm = $this->exports->farmOf($load) ?? abort(404);
        abort_unless(Farm::query()->whereKey($farm->id)->exists(), 404);   // a farm the user may reach
        $this->assertWritable($request, $farm->id);

        return $load;
    }

    public function save(Request $request, string $dispatch): RedirectResponse
    {
        $this->allow($request, R::MANAGE_EXPORTS);
        $load = $this->load($request, $dispatch);
        $s = ['nullable', 'string'];
        $data = $request->validate([
            'exporter_name' => [...$s, 'max:200'], 'exporter_address' => [...$s, 'max:500'], 'exporter_code' => [...$s, 'max:60'],
            'consignee_name' => [...$s, 'max:200'], 'consignee_address' => [...$s, 'max:500'], 'destination_country' => [...$s, 'max:60'], 'notify_party' => [...$s, 'max:500'],
            'incoterm' => ['nullable', Rule::in(ExportConsignment::INCOTERMS)], 'incoterm_place' => [...$s, 'max:120'], 'currency' => [...$s, 'size:3'],
            'transport_mode' => ['nullable', Rule::in(ExportConsignment::MODES)], 'container_no' => [...$s, 'max:20'], 'seal_no' => [...$s, 'max:40'],
            'vessel' => [...$s, 'max:120'], 'voyage' => [...$s, 'max:40'], 'awb_no' => [...$s, 'max:40'], 'flight_no' => [...$s, 'max:20'], 'carrier' => [...$s, 'max:120'],
            'booking_ref' => [...$s, 'max:60'], 'port_of_loading' => [...$s, 'max:120'], 'port_of_discharge' => [...$s, 'max:120'], 'final_destination' => [...$s, 'max:120'],
            'departure_on' => ['nullable', 'date'], 'arrival_on' => ['nullable', 'date'], 'temperature_setpoint_c' => ['nullable', 'numeric', 'between:-30,30'],
            'temperature_min_c' => ['nullable', 'numeric', 'between:-30,30'], 'temperature_max_c' => ['nullable', 'numeric', 'between:-30,30'], 'cold_treatment' => ['nullable', 'boolean'],
            'cold_treatment_protocol' => [...$s, 'max:200'], 'ventilation' => [...$s, 'max:60'], 'inspection_point' => [...$s, 'max:160'], 'inspection_on' => ['nullable', 'date'],
            'import_permit_no' => [...$s, 'max:60'], 'additional_declarations' => [...$s, 'max:1000'], 'required_documents' => ['nullable', 'array'],
            'required_documents.*' => [Rule::in(ExportDocuments::KINDS)], 'notes' => [...$s, 'max:1000'],
        ]);
        $c = $this->exports->prepare($load, array_filter($data, fn ($v) => $v !== null), $request->user());

        return back()->with('success', "Consignment {$c->reference} saved.");
    }

    public function lines(Request $request, ExportConsignment $consignment): RedirectResponse
    {
        $this->allow($request, R::MANAGE_EXPORTS);
        $this->assertWritable($request, $consignment->farm_id);
        $data = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*.id' => ['required', 'string'], 'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.hs_code' => ['nullable', 'string', 'max:12'], 'lines.*.pallet_ids' => ['nullable', 'string', 'max:1000'], 'lines.*.pallets' => ['nullable', 'integer', 'min:0'],
            'lines.*.cartons' => ['nullable', 'numeric', 'gte:0'], 'lines.*.net_kg' => ['nullable', 'numeric', 'gte:0'], 'lines.*.gross_kg' => ['nullable', 'numeric', 'gte:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gte:0'], 'lines.*.variety' => ['nullable', 'string', 'max:80'], 'lines.*.grade' => ['nullable', 'string', 'max:40'],
            'lines.*.puc' => ['nullable', 'string', 'max:60']]);
        $this->exports->updateLines($consignment, $data['lines'], $request->user());

        return back()->with('success', 'Lines saved.');
    }

    public function generate(Request $request, ExportConsignment $consignment): RedirectResponse
    {
        $this->allow($request, R::MANAGE_EXPORTS);
        $this->assertWritable($request, $consignment->farm_id);
        $data = $request->validate(['kind' => ['nullable', Rule::in(ExportDocuments::KINDS)]]);
        $docs = $this->exports->generate($consignment, $data['kind'] ?? null, $request->user());

        return back()->with('success', count($docs).' document(s) generated into Document Management.');
    }

    public function saveCode(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_EXPORTS);
        $this->assertWritable($request, $farm->id);
        $data = $request->validate(['kind' => ['required', Rule::in(ExportCode::KINDS)], 'code' => ['required', 'string', 'max:60'], 'store_id' => ['nullable', 'integer'],
            'valid_until' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:300']]);
        if (! empty($data['store_id'])) {
            abort_unless(FarmStore::query()->where('farm_id', $farm->id)->whereKey($data['store_id'])->exists(), 422, 'That store is not on this farm.');
        }
        $this->exports->saveCode($farm, $data);

        return back()->with('success', "{$data['kind']} {$data['code']} saved.");
    }

    public function deleteCode(Request $request, ExportCode $code): RedirectResponse
    {
        $this->allow($request, R::MANAGE_EXPORTS);
        $this->assertWritable($request, $code->farm_id);
        $code->delete();

        return back()->with('success', 'Code removed.');
    }
}
