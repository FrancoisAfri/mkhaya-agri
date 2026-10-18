<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\NcrAction;
use App\Modules\Operations\Manufacturing\Models\Nonconformance;
use App\Modules\Operations\Manufacturing\Models\Nonconformance as Ncr;
use App\Modules\Operations\Manufacturing\Services\Nonconformances;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Non-conformance reports and their corrective and preventive actions (MFG-009).
 */
class NonconformanceController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly Nonconformances $ncrs) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Nonconformance::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...Ncr::STATUSES, 'OPEN_ALL'])],
            'severity' => ['nullable', Rule::in(Ncr::SEVERITIES)],
            'source' => ['nullable', Rule::in(Ncr::SOURCES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $canCreate = $request->user()->can('create', Ncr::class);
        $status = $filters['status'] ?? 'OPEN_ALL';

        return Inertia::render('Modules/Manufacturing/Quality/Nonconformances', [
            'ncrs' => Ncr::query()
                ->with(['stockItem:id,code,name', 'lot:id,lot_number', 'owner.person'])
                ->when($status === 'OPEN_ALL', fn ($q) => $q->whereNotIn('status', [Ncr::CLOSED, Ncr::VOID]),
                    fn ($q) => $q->where('status', $status))
                ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
                ->when($filters['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
                ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$t}%")
                    ->orWhere('defect_code', 'ilike', "%{$t}%")->orWhere('description', 'ilike', "%{$t}%")))
                ->orderByRaw("CASE severity WHEN 'CRITICAL' THEN 0 WHEN 'MAJOR' THEN 1 ELSE 2 END")
                ->orderBy('due_date')
                ->paginate(25)->withQueryString()
                ->through(fn (Ncr $n) => $this->summarise($n)),
            'filters' => $filters + ['status' => $status],
            'statuses' => Ncr::STATUSES,
            'severities' => Ncr::SEVERITIES,
            'sources' => Ncr::SOURCES,
            // The defects that keep coming back, on a code -- the figure the register exists for.
            'repeat_defects' => Ncr::query()->whereNotNull('defect_code')->where('created_at', '>=', now()->subYear())
                ->selectRaw('defect_code, COUNT(*) AS n')->groupBy('defect_code')->havingRaw('COUNT(*) > 1')
                ->orderByDesc('n')->limit(8)->get()->map(fn ($r) => ['code' => $r->defect_code, 'count' => (int) $r->n]),
            'options' => $canCreate ? [
                'materials' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'name'])
                    ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name])->values(),
                'suppliers' => Supplier::query()->where('is_active', true)->orderBy('company_name')->limit(2000)->get(['id', 'company_name'])
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->company_name])->values(),
                'people' => $this->tenantPeopleOptions($request),
            ] : null,
            'can' => ['create' => $canCreate],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Ncr::class);

        $data = $request->validate([
            'source' => ['required', Rule::in(Ncr::SOURCES)],
            'description' => ['required', 'string', 'max:4000'],
            'defect_code' => ['nullable', 'string', 'max:64'],
            'severity' => ['required', Rule::in(Ncr::SEVERITIES)],
            'stock_item_id' => ['nullable', 'integer', $this->tenantExists('fin_stock_items')],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
            'purchase_order_id' => ['nullable', 'integer', $this->tenantExists('purchase_orders', 'id', true)],
            'qty_affected' => ['nullable', 'numeric', 'min:0'],
            'owner_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'due_date' => ['nullable', 'date'],
        ]);

        try {
            $ncr = $this->ncrs->raise($data, $this->tenantId($request), $request->user());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.quality.ncrs.show', $ncr->uuid)->with('success', "{$ncr->number} raised.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $ncr = $this->find($uuid);
        $this->authorize('view', $ncr);
        $ncr->load(['actions.owner.person', 'inspection:id,uuid,number', 'order:id,uuid,number', 'owner.person']);
        $user = $request->user();

        $supplier = $ncr->supplier_id ? Supplier::query()->find($ncr->supplier_id) : null;
        $po = $ncr->purchase_order_id ? PurchaseOrder::query()->find($ncr->purchase_order_id) : null;
        $sheq = $ncr->sheq_ncr_id ? \Illuminate\Support\Facades\DB::table('sheq_ncrs')->where('id', $ncr->sheq_ncr_id)->first(['uuid', 'reference', 'status']) : null;

        return Inertia::render('Modules/Manufacturing/Quality/NonconformanceShow', [
            'ncr' => $this->summarise($ncr) + [
                'containment' => $ncr->containment, 'root_cause' => $ncr->root_cause,
                'owner_id' => $ncr->owner_id, 'due_date' => $ncr->due_date?->toDateString(),
                'qty_affected' => $ncr->qty_affected !== null ? (float) $ncr->qty_affected : null,
                'effectiveness' => $ncr->effectiveness, 'effectiveness_notes' => $ncr->effectiveness_notes,
                'verified_at' => $ncr->verified_at?->toIso8601String(), 'void_reason' => $ncr->void_reason,
                'inspection' => $ncr->inspection ? ['id' => $ncr->inspection->uuid, 'number' => $ncr->inspection->number] : null,
                'order' => $ncr->order ? ['id' => $ncr->order->uuid, 'number' => $ncr->order->number] : null,
                'supplier' => $supplier?->company_name, 'purchase_order' => $po?->po_number,
                'sheq' => $sheq ? ['reference' => $sheq->reference, 'status' => $sheq->status] : null,
                'actions' => $ncr->actions->map(fn (NcrAction $a) => [
                    'id' => $a->uuid, 'type' => $a->type, 'description' => $a->description,
                    'owner' => $a->owner ? $this->personName($a->owner) : null, 'owner_id' => $a->owner_id,
                    'due_date' => $a->due_date?->toDateString(), 'status' => $a->status,
                    'is_overdue' => $a->status === 'OPEN' && $a->due_date && $a->due_date->copy()->endOfDay()->isPast(),
                    'completed_at' => $a->completed_at?->toIso8601String(), 'completion_notes' => $a->completion_notes,
                    'can_complete' => $a->status === 'OPEN' && $user->can('completeAction', [$ncr, $a]),
                ])->values(),
            ],
            'repeats' => $this->ncrs->repeats($ncr)->map(fn (Ncr $r) => [
                'id' => $r->uuid, 'number' => $r->number, 'status' => $r->status, 'created_at' => $r->created_at?->toDateString(),
            ])->values(),
            'people' => $user->can('update', $ncr) ? $this->tenantPeopleOptions($request) : [],
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($ncr, $user),
            'can' => [
                'update' => $user->can('update', $ncr),
                'verify' => $user->can('verify', $ncr),
                'sheq' => $user->can('update', $ncr) && ! $ncr->sheq_ncr_id && $this->ncrs->sheqAvailable(),
            ],
        ]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('update', $ncr);

        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:4000'],
            'defect_code' => ['nullable', 'string', 'max:64'],
            'severity' => ['sometimes', Rule::in(Ncr::SEVERITIES)],
            'containment' => ['nullable', 'string', 'max:4000'],
            'root_cause' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'due_date' => ['nullable', 'date'],
            'qty_affected' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->attempt(fn () => $this->ncrs->update($ncr, $data), 'Saved.');
    }

    public function storeAction(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('update', $ncr);

        $data = $request->validate([
            'type' => ['required', Rule::in(NcrAction::TYPES)],
            'description' => ['required', 'string', 'max:4000'],
            'owner_id' => ['nullable', 'integer', Rule::in(array_column($this->tenantPeople($request), 'id'))],
            'due_date' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => $this->ncrs->addAction($ncr, $data), 'Action added.');
    }

    public function completeAction(Request $request, string $uuid, string $actionUuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $action = $ncr->actions()->where('uuid', $actionUuid)->firstOrFail();
        $this->authorize('completeAction', [$ncr, $action]);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->ncrs->completeAction($action, $data['notes'] ?? null, $request->user()), 'Action done.');
    }

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('update', $ncr);

        return $this->attempt(fn () => $this->ncrs->submitForVerification($ncr), 'Ready for verification.');
    }

    public function verify(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('verify', $ncr);
        $data = $request->validate([
            'effectiveness' => ['required', Rule::in(['EFFECTIVE', 'NOT_EFFECTIVE'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => $this->ncrs->verify($ncr, $data['effectiveness'], $data['notes'] ?? null, $request->user()),
            $data['effectiveness'] === 'EFFECTIVE' ? "{$ncr->number} closed." : "{$ncr->number} is back with its actions.");
    }

    public function void(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('update', $ncr);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->ncrs->void($ncr, $data['reason']), "{$ncr->number} is void.");
    }

    public function raiseInSheq(Request $request, string $uuid): RedirectResponse
    {
        $ncr = $this->find($uuid);
        $this->authorize('update', $ncr);

        return $this->attempt(fn () => $this->ncrs->raiseInSheq($ncr, $request->user()), 'Raised in the SHEQ register.');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function attempt(callable $work, string $success): RedirectResponse
    {
        try {
            $work();
        } catch (DomainException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function find(string $uuid): Ncr
    {
        return Ncr::query()->with(['stockItem:id,code,name', 'lot:id,lot_number'])->where('uuid', $uuid)->firstOrFail();
    }

    private function tenantId(Request $request): ?int
    {
        return app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $request->user()->company_id;
    }

    private function summarise(Ncr $n): array
    {
        return [
            'id' => $n->uuid, 'number' => $n->number, 'source' => $n->source, 'status' => $n->status, 'severity' => $n->severity,
            'defect_code' => $n->defect_code, 'description' => $n->description,
            'item_code' => $n->stockItem?->code, 'item_name' => $n->stockItem?->name, 'lot' => $n->lot?->lot_number,
            'owner' => $n->owner ? $this->personName($n->owner) : null,
            'due_date' => $n->due_date?->toDateString(), 'is_overdue' => $n->isOverdue(),
            'created_at' => $n->created_at?->toDateString(),
        ];
    }

    private function personName(\App\Core\User\Models\User $user): string
    {
        $p = $user->person;

        return $p ? trim("{$p->first_name} {$p->surname}") : (string) $user->email;
    }
}
