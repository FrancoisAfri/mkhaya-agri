<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\ContractAmendment;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropType;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\GradingStandard;
use App\Modules\Agri\Models\MarketListing;
use App\Modules\Agri\Models\MarketPrice;
use App\Modules\Agri\Models\MarketRequest;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\SalesContract;
use App\Modules\Agri\Services\FarmSales;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §9: what is particular to selling a crop — supply contracts and their amendments, the
 * position per crop, market prices with trends and margins, and the marketplace's listings and buyer
 * requests. Customers, price lists, quotes, orders, invoices and disputes are Accounting's screens;
 * this page links to them.
 */
class SalesController extends AgriController
{
    public const TABS = ['contracts', 'position', 'market', 'marketplace'];

    public function __construct(private readonly FarmSales $sales) {}

    public function index(Request $request, string $tab = 'contracts'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_SALES);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $companyId = (int) $request->user()->company_id;
        $buyers = FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'verified_at', 'channel']);
        $buyerName = $buyers->mapWithKeys(fn ($b) => [$b->id => $b->name]);
        $product = $request->string('product')->value() ?: MarketPrice::query()->latest('price_date')->value('product');

        return Inertia::render('Modules/Agri/Sales/Index', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'buyers' => $buyers->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'channel' => $b->channel, 'verified' => $b->verified_at !== null])->values(),
            // Selling itself happens in Accounting: these are its screens.
            'accounting' => collect(['customers' => 'customers', 'price_lists' => 'price-lists', 'quotes' => 'quotes', 'orders' => 'sales-orders', 'disputes' => 'disputes', 'debtors' => 'debtors'])
                ->map(fn ($name) => route("finance.accounting.{$name}"))->all(),
            'data' => match ($tab) {
                'contracts' => ['contracts' => SalesContract::query()->with('amendments')->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")->orderByDesc('id')->get()
                    ->map(fn ($c) => $c->toArray() + ['buyer' => $buyerName[$c->party_id] ?? null, 'position' => $this->sales->position($c), 'price_now' => $this->sales->contractPrice($c)])],
                'position' => ['crops' => CropType::query()->whereIn('id', SalesContract::query()->whereIn('status', SalesContract::LIVE)->pluck('crop_type_id')
                    ->merge(CropPlan::query()->whereIn('status', CropPlan::LIVE)->pluck('crop_type_id'))->filter()->unique())->orderBy('name')->get()
                    ->map(fn ($t) => ['crop' => $t->name] + $this->sales->cropPosition($companyId, $t->id))->values()],
                'market' => ['product' => $product, 'products' => MarketPrice::query()->distinct()->orderBy('product')->pluck('product'),
                    'trends' => $product ? $this->sales->trends($product) : null,
                    'margins' => $product ? $this->sales->margins($product, $request->string('unit')->value() ?: (string) MarketPrice::query()->where('product', $product)->value('unit'),
                        (float) $request->input('transport', 0), (float) $request->input('handling', 0),
                        $request->filled('plan') ? CropPlan::query()->where('uuid', $request->string('plan'))->first() : null) : null,
                    'prices' => MarketPrice::query()->orderByDesc('price_date')->limit(100)->get()],
                'marketplace' => ['listings' => MarketListing::query()->orderByDesc('id')->limit(100)->get(), 'requests' => MarketRequest::query()->orderByDesc('id')->limit(100)->get(),
                    'matches' => $this->sales->matches($companyId)],
            },
            'options' => ['crop_types' => CropType::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]),
                'standards' => GradingStandard::query()->where('is_active', true)->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'title' => $s->name]),
                'plans' => CropPlan::query()->whereIn('status', CropPlan::LIVE)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->get(['id', 'uuid', 'code'])->map(fn ($p) => ['value' => $p->uuid, 'title' => $p->code, 'id' => $p->id]),
                'lots' => $farm ? ProduceLot::query()->where('farm_id', $farm->id)->where('status', 'available')->get()->map(fn ($l) => ['value' => $l->id, 'title' => "{$l->code} · {$l->product} {$l->grade} · {$l->remaining} {$l->unit}"]) : [],
                ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_SALES), 'approve' => $request->user()->can(R::APPROVE_CONTRACTS), 'dispatch' => $request->user()->can(R::MANAGE_DISPATCH)],
        ]);
    }

    public function storeContract(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate($this->contractRules() + ['farm_id' => ['nullable', 'string'], 'party_id' => ['required', 'integer', 'exists:fin_parties,id'], 'crop_type_id' => ['nullable', 'integer'],
            'variety_id' => ['nullable', 'integer'], 'product' => ['required', 'string', 'max:120'], 'unit' => ['required', 'string', 'max:12'],
            'pricing' => ['required', Rule::in(['fixed', 'formula', 'basis', 'pool'])], 'currency' => ['nullable', 'string', 'size:3'], 'standard_id' => ['nullable', 'integer'],
            'dispute_procedure' => ['nullable', 'string', 'max:1000']]);
        if (! empty($data['farm_id'])) {
            $data['farm_id'] = $this->workingFarm($request, $data['farm_id'])->id;
        }
        $c = $this->sales->contract((int) $request->user()->company_id, $data, $request->user());

        return back()->with('success', "Contract {$c->reference} drafted. Someone else approves it.");
    }

    public function contractStatus(Request $request, SalesContract $contract): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(SalesContract::STATUSES)]]);
        $this->allow($request, $data['status'] === 'active' ? R::APPROVE_CONTRACTS : R::MANAGE_SALES);
        $this->sales->setStatus($contract, $data['status'], $request->user());

        return back()->with('success', "Contract {$contract->reference} is {$data['status']}.");
    }

    public function amend(Request $request, SalesContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate($this->contractRules(false) + ['reason' => ['required', 'string', 'max:500']]);
        $this->sales->amend($contract, collect($data)->except('reason')->all(), $data['reason'], $request->user());

        return back()->with('success', 'Amendment proposed; it applies once approved.');
    }

    public function decideAmendment(Request $request, ContractAmendment $amendment): RedirectResponse
    {
        $this->allow($request, R::APPROVE_CONTRACTS);
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->sales->decideAmendment($amendment, (bool) $data['approve'], $request->user());

        return back()->with('success', $data['approve'] ? 'Amendment approved and applied.' : 'Amendment rejected.');
    }

    public function contractOrder(Request $request, SalesContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'deliver_on' => ['required', 'date'], 'delivery_address' => ['nullable', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.grade' => ['nullable', 'string', 'max:40'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0']]);
        $o = $this->sales->orderOnContract($contract, $this->workingFarm($request, $data['farm_id']), $data['lines'], $data['deliver_on'], $data['delivery_address'] ?? null, $request->user());

        return back()->with('success', "Sales order {$o->number} on {$contract->reference} created.");
    }

    public function storePrice(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['product' => ['required', 'string', 'max:120'], 'crop_type_id' => ['nullable', 'integer'], 'grade' => ['nullable', 'string', 'max:40'],
            'market' => ['required', 'string', 'max:120'], 'region' => ['nullable', 'string', 'max:80'], 'price_date' => ['required', 'date'], 'price' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'], 'unit' => ['required', 'string', 'max:12'], 'kind' => ['required', Rule::in(['actual', 'forecast'])],
            'source' => ['required', 'string', 'max:160'], 'source_url' => ['nullable', 'url', 'max:300']]);
        abort_if($data['kind'] === 'actual' && $data['price_date'] > today()->toDateString(), 422, 'An actual price cannot be in the future; record it as a forecast.');
        MarketPrice::create($data + ['company_id' => $request->user()->company_id, 'recorded_by' => $request->user()->id]);

        return back()->with('success', 'Price recorded.');
    }

    public function storeListing(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'product' => ['required', 'string', 'max:120'], 'grade' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'string', 'max:12'], 'availability' => ['required', Rule::in(['in_stock', 'forecast'])],
            'lot_ids' => ['required_if:availability,in_stock', 'array'], 'crop_plan_id' => ['required_if:availability,forecast', 'nullable', 'integer'],
            'available_from' => ['nullable', 'date'], 'asking_price' => ['nullable', 'numeric', 'gt:0'], 'currency' => ['nullable', 'string', 'size:3']]);
        $this->sales->list($this->workingFarm($request, $data['farm_id']), $data, $request->user());

        return back()->with('success', $data['availability'] === 'in_stock' ? 'Listed as in stock.' : 'Listed as forecast — not yet harvested.');
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_SALES);
        $data = $request->validate(['party_id' => ['nullable', 'integer', 'exists:fin_parties,id'], 'buyer_name' => ['required', 'string', 'max:160'], 'product' => ['required', 'string', 'max:120'],
            'grade' => ['nullable', 'string', 'max:40'], 'quantity' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'string', 'max:12'],
            'needed_by' => ['nullable', 'date'], 'target_price' => ['nullable', 'numeric', 'gt:0']]);
        MarketRequest::create($data + ['company_id' => $request->user()->company_id]);

        return back()->with('success', 'Buyer request recorded.');
    }

    private function contractRules(bool $create = true): array
    {
        $req = $create ? 'required' : 'sometimes';

        return ['quantity' => [$req, 'numeric', 'gt:0'], 'tolerance_pct' => ['nullable', 'numeric', 'between:0,50'],
            'windows' => [$req, 'array', 'min:1'], 'windows.*.from' => ['required', 'date'], 'windows.*.to' => ['required', 'date'], 'windows.*.quantity' => ['required', 'numeric', 'gt:0'],
            'windows.*.location' => ['nullable', 'string', 'max:200'], 'accepted_grades' => ['nullable', 'array'], 'quality_spec' => ['nullable', 'string', 'max:1000'],
            'price' => ['nullable', 'numeric', 'gt:0'], 'formula' => ['nullable', 'array'], 'formula.market' => ['nullable', 'string', 'max:120'], 'formula.product' => ['nullable', 'string', 'max:120'],
            'formula.grade' => ['nullable', 'string', 'max:40'], 'formula.unit' => ['nullable', 'string', 'max:12'], 'formula.lookback_days' => ['nullable', 'integer', 'between:1,90'],
            'formula.adjust_pct' => ['nullable', 'numeric'], 'formula.premium' => ['nullable', 'numeric'], 'formula.floor' => ['nullable', 'numeric'], 'formula.cap' => ['nullable', 'numeric'],
            'formula.grade_premiums' => ['nullable', 'array'], 'formula.contract_month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'formula.basis' => ['nullable', 'numeric'],
            'formula.pool_price' => ['nullable', 'numeric', 'gt:0'], 'formula.pool_final' => ['nullable', 'boolean'], 'packaging' => ['nullable', 'string', 'max:500'], 'transport' => ['nullable', Rule::in(['farm', 'buyer'])],
            'rejection_rules' => ['nullable', 'string', 'max:1000'], 'advance_amount' => ['nullable', 'numeric', 'gte:0'], 'input_financing' => ['nullable', 'array'],
            'input_financing.*.item' => ['required', 'string', 'max:120'], 'input_financing.*.value' => ['required', 'numeric', 'gt:0'], 'input_financing.*.recover_from' => ['nullable', 'string', 'max:120']];
    }
}
