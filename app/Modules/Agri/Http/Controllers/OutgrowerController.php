<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\Grower;
use App\Modules\Agri\Models\GrowerAccount;
use App\Modules\Agri\Models\GrowerAdvance;
use App\Modules\Agri\Models\GrowerCollection;
use App\Modules\Agri\Models\GrowerContract;
use App\Modules\Agri\Models\GrowerInspection;
use App\Modules\Agri\Models\GrowerNotice;
use App\Modules\Agri\Models\GrowerReport;
use App\Modules\Agri\Models\GrowerSettlement;
use App\Modules\Agri\Models\GrowerVisit;
use App\Modules\Agri\Services\GrowerPortal;
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
    public function __construct(private readonly Outgrowers $outgrowers, private readonly GrowerPortal $portal) {}

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
            // The grower portal: who has a sign-in, what they did with it, and what they sent in.
            'portal' => [
                'accounts' => GrowerAccount::query()->whereIn('grower_id', $ids)->get()->map(fn (GrowerAccount $a) => ['id' => $a->uuid, 'grower_id' => $a->grower_id,
                    'grower' => $code[$a->grower_id] ?? null, 'name' => $a->name, 'mobile' => $a->mobile, 'email' => $a->email, 'locale' => $a->locale, 'status' => $a->status,
                    'mobile_verified' => (bool) $a->mobile_verified_at, 'last_login_at' => $a->last_login_at?->format('Y-m-d H:i'), 'invited_at' => $a->invited_at?->format('Y-m-d')])->values(),
                'activity' => $this->portal->activity($ids, 100),
                'reports' => GrowerReport::query()->whereIn('grower_id', $ids)->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(100)->get()
                    ->map(fn (GrowerReport $r) => ['id' => $r->uuid, 'grower' => $code[$r->grower_id] ?? null, 'kind' => $r->kind, 'date' => $r->created_at?->format('Y-m-d'),
                        'planted_on' => $r->planted_on?->toDateString(), 'hectares' => $r->hectares, 'details' => $r->details, 'has_photo' => (bool) $r->photo_name,
                        'status' => $r->status, 'response' => $r->response])->values(),
                'visits' => GrowerVisit::query()->whereIn('grower_id', $ids)->whereDate('planned_on', '>=', today()->subDays(30))->orderBy('planned_on')->get()
                    ->map(fn (GrowerVisit $v) => ['id' => $v->uuid, 'grower' => $code[$v->grower_id] ?? null, 'date' => $v->planned_on?->toDateString(), 'purpose' => $v->purpose,
                        'notes' => $v->notes, 'status' => $v->status])->values(),
                'notices' => $farm ? GrowerNotice::query()->where('farm_id', $farm->id)->orderByDesc('id')->limit(50)->get()
                    ->map(fn (GrowerNotice $n) => ['id' => $n->uuid, 'grower' => $n->grower_id ? ($code[$n->grower_id] ?? null) : null, 'title' => $n->title, 'body' => $n->body,
                        'date' => $n->created_at?->format('Y-m-d')])->values() : [],
                'login_url' => route('grower.portal.login', ['c' => \App\Modules\Tenancy\Models\Company::query()
                    ->whereKey(app(\App\Modules\Tenancy\Services\CompanyBranding::class)->companyIdFor($request->user()))->value('uuid')]),
            ],
        ]);
    }

    // ── the grower portal ────────────────────────────────────────────────────────────────────

    public function invite(Request $request, Grower $grower): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $this->assertWritable($request, $grower->farm_id);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:160'], 'mobile' => ['nullable', 'string', 'max:20', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:mobile'], 'locale' => ['nullable', Rule::in(GrowerAccount::LOCALES)]]);
        $a = $this->portal->invite($grower, $data, $request->user());

        return back()->with('success', "{$grower->code} is invited to the grower portal; they sign in with a code sent to ".($a->mobile ?? $a->email).'.');
    }

    public function revoke(Request $request, GrowerAccount $account): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $this->assertWritable($request, Grower::query()->findOrFail($account->grower_id)->farm_id);
        $this->portal->revoke($account, $request->user(), $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null);

        return back()->with('success', 'Portal access withdrawn: the grower is signed out and their number no longer gets answers.');
    }

    public function restore(Request $request, GrowerAccount $account): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $this->assertWritable($request, Grower::query()->findOrFail($account->grower_id)->farm_id);
        $this->portal->restore($account, $request->user());

        return back()->with('success', 'Portal access restored.');
    }

    public function notice(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'grower_id' => ['nullable', 'string'], 'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $grower = filled($data['grower_id'] ?? null) ? Grower::query()->where('farm_id', $farm->id)->where('uuid', $data['grower_id'])->firstOrFail() : null;
        $this->portal->notice($farm, $grower, $data['title'], $data['body'], $request->user());

        return back()->with('success', $grower ? "Message sent to {$grower->code}." : 'Message sent to every grower of '.$farm->name.'.');
    }

    public function scheduleVisit(Request $request, GrowerContract $contract): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS, R::RECORD_ACTIVITIES);
        $this->assertWritable($request, Grower::query()->findOrFail($contract->grower_id)->farm_id);
        $data = $request->validate(['planned_on' => ['required', 'date', 'after_or_equal:today'], 'purpose' => ['required', Rule::in(['inspection', 'field_visit', 'collection', 'training'])],
            'notes' => ['nullable', 'string', 'max:500']]);
        $this->portal->scheduleVisit($contract, $data, $request->user());

        return back()->with('success', 'Visit planned; the grower sees it in their portal.');
    }

    public function answerReport(Request $request, GrowerReport $growerReport): RedirectResponse
    {
        $this->allow($request, R::MANAGE_OUTGROWERS);
        $this->assertWritable($request, Grower::query()->findOrFail($growerReport->grower_id)->farm_id);
        $data = $request->validate(['response' => ['required', 'string', 'max:1000'], 'status' => ['nullable', Rule::in(['answered', 'closed'])]]);
        $this->portal->answer($growerReport, $data['response'], $data['status'] ?? 'answered', $request->user());

        return back()->with('success', 'Answer sent to the grower.');
    }

    /** The photo a grower sent with a report, from the database. */
    public function reportPhoto(Request $request, GrowerReport $growerReport)
    {
        $this->allow($request, R::VIEW_OUTGROWERS);
        abort_unless($growerReport->photo_name, 404);

        return response(base64_decode((string) $growerReport->photo_data), 200, ['Content-Type' => $growerReport->photo_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($growerReport->photo_name).'"', 'Cache-Control' => 'private, max-age=600']);
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
