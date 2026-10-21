<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAdvance;
use App\Modules\Agri\Models\GrowerCollection;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerInspection;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Services\Outgrowers;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 2 §16D: growers, their contracts, advances, inspections, collections and settlements. */
class OutgrowerController extends AgriController
{
    public function __construct(private readonly Outgrowers $outgrowers) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_OUTGROWERS);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $growers = $farm ? Grower::query()->where('farm_id', $farm->id)->with('party')->orderBy('code')->get() : collect();
        $ids = $growers->pluck('id');
        $code = $growers->pluck('code', 'id');

        return Inertia::render('Modules/Agri/Outgrowers', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'growers' => $growers->map(fn ($g) => ['id' => $g->id, 'uuid' => $g->uuid, 'code' => $g->code, 'name' => $g->party?->name, 'location' => $g->location, 'hectares' => $g->hectares]
                + $this->outgrowers->statement($g))->values(),
            'contracts' => GrowerContract::query()->whereIn('grower_id', $ids)->orderByDesc('id')->get()->map(fn ($c) => $c->toArray() + ['grower' => $code[$c->grower_id] ?? null]),
            'advances' => GrowerAdvance::query()->whereIn('grower_id', $ids)->orderByDesc('issued_on')->limit(100)->get()->map(fn ($a) => $a->toArray() + ['grower' => $code[$a->grower_id] ?? null]),
            'inspections' => GrowerInspection::query()->whereIn('grower_id', $ids)->orderByDesc('inspected_on')->limit(100)->get()->map(fn ($i) => $i->toArray() + ['grower' => $code[$i->grower_id] ?? null]),
            'collections' => GrowerCollection::query()->whereIn('grower_id', $ids)->orderByDesc('collected_on')->limit(100)->get()->map(fn ($c) => $c->toArray() + ['grower' => $code[$c->grower_id] ?? null]),
            'settlements' => GrowerSettlement::query()->whereIn('grower_id', $ids)->orderByDesc('id')->limit(100)->get()->map(fn ($s) => $s->toArray() + ['grower' => $code[$s->grower_id] ?? null]),
            'forecast' => $farm ? $this->outgrowers->forecast($farm->id) : [],
            'options' => ['stores' => $farm ? FarmStore::query()->where('farm_id', $farm->id)->get(['uuid', 'name'])->map(fn ($s) => ['value' => $s->uuid, 'title' => $s->name]) : [],
                'items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'name'])->map(fn ($i) => ['value' => $i->id, 'title' => $i->name])],
            'can' => ['manage' => $request->user()->can(R::MANAGE_OUTGROWERS), 'approve' => $request->user()->can(R::APPROVE_CONTRACTS)],
        ]);
    }

    public function storeGrower(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'name' => ['required', 'string', 'max:160'], 'code' => ['required', 'string', 'max:30'], 'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:200'], 'hectares' => ['nullable', 'numeric', 'gt:0'], 'bank_name' => ['nullable', 'string', 'max:80'], 'bank_account' => ['nullable', 'string', 'max:40']]);
        $this->outgrowers->register($this->workingFarm($request, $data['farm_id']), $data, $request->user());

        return back()->with('success', 'Grower registered as a supplier in Finance.');
    }

    public function storeContract(Request $request, Grower $grower): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $data = $request->validate(['crop_type_id' => ['nullable', 'integer'], 'product' => ['required', 'string', 'max:120'], 'hectares' => ['nullable', 'numeric', 'gt:0'],
            'expected_quantity' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'string', 'max:12'], 'price' => ['required', 'numeric', 'gt:0'], 'grade_prices' => ['nullable', 'array'],
            'advance_limit' => ['nullable', 'numeric', 'gte:0']]);
        $c = $this->outgrowers->contract($grower, $data, $request->user());

        return back()->with('success', "Contract {$c->reference} drafted; someone else approves it.");
    }

    public function approveContract(Request $request, GrowerContract $contract): RedirectResponse
    {
        $this->allow($request, R::APPROVE_CONTRACTS);
        $this->outgrowers->approve($contract, $request->user());

        return back()->with('success', 'Contract approved.');
    }

    public function advance(Request $request, GrowerContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $data = $request->validate(['kind' => ['required', Rule::in(['input', 'cash'])], 'stock_item_id' => ['required_if:kind,input', 'nullable', 'integer'],
            'quantity' => ['required_if:kind,input', 'nullable', 'numeric', 'gt:0'], 'amount' => ['required_if:kind,cash', 'nullable', 'numeric', 'gt:0'], 'issued_on' => ['nullable', 'date', 'before_or_equal:today']]);
        $a = $this->outgrowers->advance($contract, $data, $request->user());

        return back()->with('success', 'Advance of R'.number_format($a->value, 2).' recorded against the grower.');
    }

    public function inspect(Request $request, GrowerContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS, R::RECORD_ACTIVITIES);
        $data = $request->validate(['inspected_on' => ['required', 'date', 'before_or_equal:today'], 'stage' => ['nullable', 'string', 'max:40'], 'estimated_quantity' => ['nullable', 'numeric', 'gte:0'],
            'outcome' => ['required', Rule::in(['satisfactory', 'concern', 'failed'])], 'notes' => ['nullable', 'string', 'max:1000']]);
        $this->outgrowers->inspect($contract, $data, $request->user());

        return back()->with('success', 'Inspection recorded.');
    }

    public function collect(Request $request, GrowerContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS, R::RECORD_HARVEST);
        $data = $request->validate(['store_id' => ['required', 'string'], 'stock_item_id' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'], 'rejected' => ['nullable', 'numeric', 'gte:0'],
            'grade' => ['nullable', 'string', 'max:40'], 'collected_on' => ['nullable', 'date', 'before_or_equal:today'], 'location' => ['nullable', 'string', 'max:80'], 'client_id' => ['nullable', 'string', 'max:64']]);
        $grower = Grower::query()->findOrFail($contract->grower_id);
        $store = FarmStore::query()->where('farm_id', $grower->farm_id)->where('uuid', $data['store_id'])->firstOrFail();
        $col = $this->outgrowers->collect($contract, $store, FinStockItem::query()->findOrFail($data['stock_item_id']), $data, $request->user());

        return back()->with('success', 'Collected: R'.number_format($col->value, 2).' at '.number_format($col->price, 2).' per unit.');
    }

    public function settle(Request $request, Grower $grower): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $s = $this->outgrowers->draftSettlement($grower, $request->user());

        return back()->with('success', "Settlement {$s->reference}: R".number_format($s->net, 2).' net. Someone else approves it.');
    }

    public function approveSettlement(Request $request, GrowerSettlement $settlement): RedirectResponse
    {
        $this->allow($request, R::APPROVE_CONTRACTS);
        $this->outgrowers->approveSettlement($settlement, $request->user());

        return back()->with('success', 'Settlement approved: billed in Finance, advances recovered.');
    }
}
