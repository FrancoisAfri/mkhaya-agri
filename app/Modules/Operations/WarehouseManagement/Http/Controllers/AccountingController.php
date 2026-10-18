<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\AccountingIssue;
use App\Modules\Operations\WarehouseManagement\Models\AccountingRule;
use App\Modules\Operations\WarehouseManagement\Models\LandedCost;
use App\Modules\Operations\WarehouseManagement\Models\Revaluation;
use App\Modules\Operations\WarehouseManagement\Services\CostReports;
use App\Modules\Operations\WarehouseManagement\Services\Valuation;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAccounting;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-100..103: event treatments, reconciliation, landed costs, revaluations and cost reports. */
class AccountingController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, WarehouseAccounting $accounting, Valuation $valuation): Response
    {
        abort_unless($request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $cid = $this->cid($request);
        if ($cid) {
            $accounting->install($cid);
        }

        return Inertia::render('Modules/Warehouse/Accounting/Index', [
            'rules' => AccountingRule::query()->orderByRaw("CASE owner WHEN 'WAREHOUSE' THEN 0 ELSE 1 END")->orderBy('event')->get()->map(fn (AccountingRule $r) => [
                'id' => $r->id, 'event' => $r->event, 'owner' => $r->owner, 'treatment' => $r->treatment, 'description' => $r->description,
                'debit' => $r->debit ? ($r->debit === 'INVENTORY' ? 'INVENTORY' : $accounting->code($r->debit)) : null,
                'credit' => $r->credit ? ($r->credit === 'INVENTORY' ? 'INVENTORY' : $accounting->code($r->credit)) : null,
                'approved' => (bool) $r->approved_at, 'approved_at' => $r->approved_at?->toIso8601String(), 'suspended' => (bool) $r->suspended_at,
                'suspended_reason' => $r->suspended_reason]),
            'issues' => AccountingIssue::query()->where('status', $request->input('issues', 'OPEN'))->orderByRaw("CASE severity WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 ELSE 2 END")
                ->latest('id')->limit(200)->get()->map(fn (AccountingIssue $i) => ['id' => $i->uuid, 'kind' => $i->kind, 'severity' => $i->severity, 'message' => $i->message,
                    'amount' => (float) $i->amount, 'status' => $i->status, 'resolution' => $i->resolution, 'seen' => $i->last_seen_at?->toIso8601String()]),
            'valuation' => $cid ? collect($accounting->subledgerValue($cid))->map(fn ($v, $acc) => ['account' => \Illuminate\Support\Facades\DB::table('fin_accounts')->where('id', $acc)->value('code'), 'value' => round($v, 2)])->values() : [],
            'landed' => LandedCost::query()->latest('id')->limit(50)->get()->map(fn (LandedCost $l) => ['id' => $l->uuid, 'number' => $l->number, 'status' => $l->status,
                'total' => (float) $l->total, 'basis' => $l->basis, 'invoice_reference' => $l->invoice_reference, 'to_inventory' => (float) $l->to_inventory,
                'to_cogs' => (float) $l->to_cogs, 'components' => $l->components, 'grn' => $l->grn?->grn_number]),
            'revaluations' => Revaluation::query()->with(['item:id,code,name', 'requester:id,email'])->latest('id')->limit(50)->get()->map(fn (Revaluation $r) => [
                'id' => $r->uuid, 'number' => $r->number, 'item' => $r->item?->code, 'qty' => (float) $r->qty, 'old_cost' => (float) $r->old_cost,
                'new_cost' => (float) $r->new_cost, 'delta' => (float) $r->delta, 'reason' => $r->reason, 'status' => $r->status, 'by' => $r->requester?->email,
                'mine' => (int) $r->requested_by === $request->user()->id, 'decision_note' => $r->decision_note]),
            'grns' => GoodsReceivedNote::query()->latest('id')->limit(30)->get(['id', 'grn_number'])->map(fn ($g) => ['id' => $g->id, 'title' => $g->grn_number]),
            'kinds' => LandedCost::KINDS,
            'filters' => ['issues' => $request->input('issues', 'OPEN')],
            'can' => ['approve' => $request->user()->can(RoleCatalog::APPROVE_ACCOUNTING)],
        ]);
    }

    public function costs(Request $request, CostReports $reports): Response
    {
        abort_unless($request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $warehouse = $request->filled('warehouse') ? FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail() : null;

        return Inertia::render('Modules/Warehouse/Accounting/Costs', [
            'report' => $reports->report($warehouse?->id, $from, $to, (int) $request->input('slow_days', 90)),
            'filters' => ['from' => $from, 'to' => $to, 'warehouse' => $warehouse?->uuid, 'slow_days' => (int) $request->input('slow_days', 90)],
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
        ]);
    }

    public function rule(Request $request, string $id, WarehouseAccounting $accounting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::APPROVE_ACCOUNTING), 403);
        $rule = AccountingRule::query()->findOrFail((int) $id);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'suspend'])], 'reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $data['action'] === 'approve' ? $accounting->approve($rule, $request->user()) : $accounting->suspend($rule, (string) ($data['reason'] ?? '')),
            $data['action'] === 'approve' ? 'Treatment approved.' : 'Posting suspended.');
    }

    public function reconcile(Request $request, WarehouseAccounting $accounting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $cid = $this->cid($request);
        abort_unless($cid, 404);
        $r = $accounting->reconcile($cid);

        return back()->with('success', "Reconciled: {$r['open']} open exception(s), {$r['cleared']} cleared.");
    }

    public function resolve(Request $request, string $uuid, WarehouseAccounting $accounting): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::APPROVE_ACCOUNTING), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['RESOLVED', 'ACCEPTED'])], 'resolution' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $accounting->resolve(AccountingIssue::query()->where('uuid', $uuid)->firstOrFail(), $data['status'], $data['resolution'], $request->user()), 'Exception closed.');
    }

    public function storeLanded(Request $request, Valuation $valuation): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $data = $request->validate([
            'grn_id' => ['nullable', 'integer'], 'basis' => ['required', Rule::in(['VALUE', 'QTY', 'WEIGHT'])], 'invoice_reference' => ['nullable', 'string', 'max:120'],
            'components' => ['required', 'array', 'min:1'], 'components.*.kind' => ['required', Rule::in(LandedCost::KINDS)],
            'components.*.description' => ['nullable', 'string', 'max:200'], 'components.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines' => ['nullable', 'array'], 'lines.*.item' => ['required', 'string'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        if (! empty($data['grn_id'])) {
            GoodsReceivedNote::query()->findOrFail($data['grn_id']);
        }
        $lines = array_map(fn ($l) => ['item_id' => FinStockItem::query()->where('uuid', $l['item'])->firstOrFail()->id, 'qty' => (float) $l['qty']], $data['lines'] ?? []);

        return $this->attempt(fn () => $valuation->draftLandedCost(['lines' => $lines] + $data, $request->user()), 'Landed cost drafted.');
    }

    public function postLanded(Request $request, string $uuid, Valuation $valuation): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::APPROVE_ACCOUNTING), 403);

        return $this->attempt(fn () => $valuation->postLandedCost(LandedCost::query()->where('uuid', $uuid)->firstOrFail(), $request->user()), 'Landed cost posted.');
    }

    public function requestRevaluation(Request $request, Valuation $valuation): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $data = $request->validate(['item' => ['required', 'string'], 'new_cost' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $valuation->requestRevaluation(FinStockItem::query()->where('uuid', $data['item'])->firstOrFail(), (float) $data['new_cost'],
            $data['reason'], $request->user()), 'Revaluation requested; it waits for a second person.');
    }

    public function decideRevaluation(Request $request, string $uuid, Valuation $valuation): RedirectResponse
    {
        $r = Revaluation::query()->where('uuid', $uuid)->firstOrFail();
        // In their own right, or as somebody's delegate (WMS-191).
        abort_unless($request->user()->can(RoleCatalog::APPROVE_ACCOUNTING)
            || app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals::class)->mayDecide($request->user(), 'REVALUATION', $r->id, (int) $r->requested_by), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $valuation->decideRevaluation($r, $data['decision'] === 'approve',
            $data['note'] ?? null, $request->user()), $data['decision'] === 'approve' ? 'Revaluation posted.' : 'Revaluation rejected.');
    }

    private function cid(Request $request): ?int
    {
        $id = app(CurrentCompany::class)->id() ?? $request->user()?->company_id;

        return $id ? (int) $id : null;
    }
}
