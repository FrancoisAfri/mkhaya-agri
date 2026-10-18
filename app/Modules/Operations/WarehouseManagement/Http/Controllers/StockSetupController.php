<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\HR\Support\PicksTenantPeople;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\ClientAccess;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Services\ClientVisibility;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reason codes (WMS-020, Part A objective 3) and third-party client access (WMS-001). */
class StockSetupController extends Controller
{
    use PicksTenantPeople, RefusesPolitely;

    public function reasons(Request $request, StockAdjustments $adjustments): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        if ($cid = $this->cid($request)) {
            $adjustments->ensureReasonCodes($cid);
        }

        return Inertia::render('Modules/Warehouse/Stock/ReasonCodes', [
            'reasons' => ReasonCode::query()->orderBy('category')->orderBy('code')->get()
                ->map(fn (ReasonCode $r) => $r->only(['uuid', 'code', 'name', 'category', 'direction', 'approval_qty', 'approval_value', 'always_requires_approval', 'is_active', 'requires_evidence', 'approve_high_risk'])
                    + ['used' => DB::table('wms_stock_adjustments')->where('reason_code_id', $r->id)->count()]),
            'categories' => ReasonCode::CATEGORIES,
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_CLIENT_ACCESS)],
        ]);
    }

    public function saveReason(Request $request, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_CLIENT_ACCESS), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(ReasonCode::CATEGORIES)],
            'direction' => ['required', Rule::in(['IN', 'OUT', 'BOTH'])],
            'approval_qty' => ['nullable', 'numeric', 'min:0'],
            'approval_value' => ['nullable', 'numeric', 'min:0'],
            'always_requires_approval' => ['boolean'],
            'is_active' => ['boolean'],
            'requires_evidence' => ['boolean'],
            'approve_high_risk' => ['boolean'],
        ]);

        return $this->attempt(function () use ($uuid, $data, $request) {
            $row = $uuid ? ReasonCode::query()->where('uuid', $uuid)->firstOrFail() : new ReasonCode(['company_id' => $this->cid($request)]);
            if (! $uuid && ReasonCode::query()->where('code', $data['code'])->exists()) {
                throw new \RuntimeException("Reason code {$data['code']} already exists.");
            }
            // A code already on posted movements keeps its code: the movements name it.
            if ($uuid && $row->code !== $data['code'] && DB::table('fin_stock_movements')->where('reason_code', $row->code)->exists()) {
                throw new \RuntimeException("{$row->code} is on posted movements; rename it, but keep its code.");
            }
            $row->fill($data)->save();
        }, 'Reason code saved.');
    }

    public function clients(Request $request, ClientVisibility $visibility): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $cid = $this->cid($request);
        $people = collect($this->tenantPeopleOptions($request))->keyBy('id');

        $owners = FinStockItem::query()->whereNotNull('owner_party_id')->groupBy('owner_party_id')
            ->selectRaw('owner_party_id, COUNT(*) AS items')->pluck('items', 'owner_party_id');

        return Inertia::render('Modules/Warehouse/Stock/Clients', [
            'clients' => DB::table('fin_parties')->whereIn('id', $owners->keys())->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'items' => (int) $owners[$p->id],
                    'people' => ClientAccess::query()->where('party_id', $p->id)->pluck('user_id')
                        ->map(fn ($u) => ['id' => (int) $u, 'name' => $people[$u]['name'] ?? '#'.$u])->values()]),
            'parties' => DB::table('fin_parties')->where('is_active', true)->when($cid, fn ($q) => $q->where('company_id', $cid))
                ->orderBy('name')->limit(1000)->get(['id', 'name']),
            'people' => array_values($people->all()),
            // Who sees every client without a grant -- the people a client would ask about first.
            'all_clients_holders' => \App\Core\User\Models\User::permission(RoleCatalog::ALL_CLIENTS)
                ->whereIn('users.id', $people->keys())->pluck('users.id')
                ->map(fn ($id) => $people[$id]['name'] ?? '#'.$id)->values(),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_CLIENT_ACCESS)],
            'sees_all' => $visibility->clientsFor($request->user()) === null,
        ]);
    }

    public function grant(Request $request, ClientVisibility $visibility): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_CLIENT_ACCESS), 403);
        $cid = $this->cid($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($this->tenantPeopleIds($request))],
            'party_id' => ['required', 'integer', Rule::exists('fin_parties', 'id')->when($cid, fn ($r) => $r->where('company_id', $cid))],
        ]);

        return $this->attempt(fn () => $visibility->grant((int) $data['user_id'], (int) $data['party_id']), 'Access granted.');
    }

    public function revoke(Request $request, ClientVisibility $visibility): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_CLIENT_ACCESS), 403);
        $data = $request->validate(['user_id' => ['required', 'integer'], 'party_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $visibility->revoke((int) $data['user_id'], (int) $data['party_id']), 'Access removed.');
    }

    private function cid(Request $request): ?int
    {
        return app(CurrentCompany::class)->id() ?? $request->user()?->company_id;
    }
}
