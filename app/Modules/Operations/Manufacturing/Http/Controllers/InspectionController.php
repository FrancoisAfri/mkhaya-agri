<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Models\InspectionResult;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inspection queue and each inspection (MFG-009): receive material into quarantine for
 * inspection, hold suspect stock, record results, and decide.
 */
class InspectionController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly QualityControl $quality) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Inspection::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in([...Inspection::STATUSES, 'PENDING'])],
            'stage' => ['nullable', Rule::in(InspectionPlan::STAGES)],
            'result' => ['nullable', Rule::in([Inspection::PENDING, Inspection::PASSED, Inspection::FAILED])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $canCreate = $request->user()->can('create', Inspection::class);

        $inspections = Inspection::query()
            ->with(['stockItem:id,code,name', 'lot:id,lot_number', 'order:id,uuid,number', 'plan:id,code'])
            ->when($filters['status'] ?? 'PENDING', fn ($q, $s) => $s === 'PENDING'
                ? $q->where('status', '!=', Inspection::CLOSED)
                : $q->where('status', $s))
            ->when($filters['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
            ->when($filters['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->when($filters['search'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$t}%")
                ->orWhereHas('lot', fn ($l) => $l->where('lot_number', 'ilike', "%{$t}%"))
                ->orWhereHas('stockItem', fn ($s) => $s->where('code', 'ilike', "%{$t}%")->orWhere('name', 'ilike', "%{$t}%"))))
            ->orderByRaw("CASE status WHEN 'AWAITING_DISPOSITION' THEN 0 WHEN 'OPEN' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Inspection $i) => $this->summarise($i));

        return Inertia::render('Modules/Manufacturing/Quality/Inspections', [
            'inspections' => $inspections,
            'filters' => $filters + ['status' => $filters['status'] ?? 'PENDING'],
            'stages' => InspectionPlan::STAGES,
            'counts' => [
                'open' => Inspection::query()->where('status', Inspection::OPEN)->count(),
                'awaiting' => Inspection::query()->where('status', Inspection::AWAITING_DISPOSITION)->count(),
                'failed' => Inspection::query()->where('status', '!=', Inspection::CLOSED)->where('result', Inspection::FAILED)->count(),
            ],
            'options' => $canCreate ? [
                'materials' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(2000)->get(['id', 'code', 'name', 'avg_cost', 'standard_cost'])
                    ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name,
                        'cost' => (float) $s->avg_cost ?: (float) $s->standard_cost])->values(),
                'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name])->values(),
                'suppliers' => Supplier::query()->where('is_active', true)->orderBy('company_name')->limit(2000)->get(['id', 'company_name'])
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->company_name])->values(),
                'purchase_orders' => PurchaseOrder::query()->whereNotIn('status', ['cancelled', 'draft'])->orderByDesc('id')->limit(500)
                    ->get(['id', 'po_number', 'supplier_id'])
                    ->map(fn ($p) => ['id' => $p->id, 'number' => $p->po_number, 'supplier_id' => $p->supplier_id])->values(),
            ] : null,
            'can' => ['create' => $canCreate],
        ]);
    }

    /** Receive purchased material into quarantine, with its inspection opened. */
    public function receive(Request $request): RedirectResponse
    {
        $this->authorize('create', Inspection::class);

        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items')],
            'warehouse_id' => ['required', 'integer', $this->tenantExists('fin_warehouses')],
            'qty' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'lot_number' => ['required', 'string', 'max:64'],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
            'purchase_order_id' => ['nullable', 'integer', $this->tenantExists('purchase_orders', 'id', true)],
            'supplier_lot_number' => ['nullable', 'string', 'max:64'],
            'expires_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $inspection = $this->quality->receiveForInspection([
                'stock_item' => FinStockItem::query()->findOrFail($data['stock_item_id']),
                'warehouse' => FinWarehouse::query()->findOrFail($data['warehouse_id']),
            ] + $data, $request->user());
        } catch (DomainException|PostingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.quality.inspections.show', $inspection->uuid)
            ->with('success', "Received into quarantine; inspection {$inspection->number} is open.");
    }

    /** Put usable stock on hold pending inspection. */
    public function hold(Request $request): RedirectResponse
    {
        $this->authorize('create', Inspection::class);

        $data = $request->validate([
            'stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items')],
            'warehouse_id' => ['required', 'integer', $this->tenantExists('fin_warehouses')],
            'qty' => ['required', 'numeric', 'gt:0'],
            'lot_number' => ['nullable', 'string', 'max:64'],
            'stage' => ['required', Rule::in(InspectionPlan::STAGES)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        $lotId = null;
        if (filled($data['lot_number'] ?? null)) {
            $lotId = FinStockLot::withoutGlobalScopes()->where('item_id', $item->id)->where('lot_number', trim($data['lot_number']))->value('id');
            if (! $lotId) {
                return back()->withInput()->with('error', 'There is no lot with that number for this material.');
            }
        }

        try {
            $inspection = $this->quality->holdForInspection($item, FinWarehouse::query()->findOrFail($data['warehouse_id']),
                (float) $data['qty'], $lotId, $data['stage'], $data['reason'], $request->user());
        } catch (DomainException|PostingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.quality.inspections.show', $inspection->uuid)
            ->with('success', "Stock is on hold; inspection {$inspection->number} is open.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $inspection = $this->find($uuid);
        $this->authorize('view', $inspection);
        $inspection->load(['plan.characteristics', 'results', 'order:id,uuid,number,status', 'operation:id,sequence,name', 'ncr:id,uuid,number,status', 'warehouse:id,code,name']);
        $user = $request->user();

        $supplier = $inspection->supplier_id ? Supplier::query()->find($inspection->supplier_id) : null;
        $po = $inspection->purchase_order_id ? PurchaseOrder::query()->find($inspection->purchase_order_id) : null;

        return Inertia::render('Modules/Manufacturing/Quality/InspectionShow', [
            'inspection' => $this->summarise($inspection) + [
                'plan' => $inspection->plan ? [
                    'id' => $inspection->plan->uuid, 'code' => $inspection->plan->code, 'name' => $inspection->plan->name,
                    'requires_certificate' => (bool) $inspection->plan->requires_certificate,
                    'blocks_production' => (bool) $inspection->plan->blocks_production,
                ] : null,
                'checks' => $inspection->plan?->characteristics->map(fn ($c) => [
                    'id' => $c->uuid, 'name' => $c->name, 'kind' => $c->kind, 'uom' => $c->uom,
                    'target' => $c->target !== null ? (float) $c->target : null,
                    'lower_limit' => $c->lower_limit !== null ? (float) $c->lower_limit : null,
                    'upper_limit' => $c->upper_limit !== null ? (float) $c->upper_limit : null,
                    'is_critical' => (bool) $c->is_critical, 'method' => $c->method,
                ])->values() ?? [],
                'results' => $inspection->results->map(fn (InspectionResult $r) => [
                    'id' => $r->id, 'sample_no' => (int) $r->sample_no, 'name' => $r->name, 'kind' => $r->kind, 'uom' => $r->uom,
                    'lower_limit' => $r->lower_limit !== null ? (float) $r->lower_limit : null,
                    'upper_limit' => $r->upper_limit !== null ? (float) $r->upper_limit : null,
                    'measured_value' => $r->measured_value !== null ? (float) $r->measured_value : null,
                    'passed' => (bool) $r->passed, 'is_critical' => (bool) $r->is_critical, 'notes' => $r->notes,
                ])->values(),
                'warehouse' => $inspection->warehouse ? "{$inspection->warehouse->code} — {$inspection->warehouse->name}" : null,
                'held_status' => $inspection->held_status,
                'operation' => $inspection->operation ? "{$inspection->operation->sequence} {$inspection->operation->name}" : null,
                'order_status' => $inspection->order?->status,
                'supplier' => $supplier?->company_name,
                'purchase_order' => $po?->po_number,
                'receipt_reference' => $inspection->receipt_reference,
                'ncr' => $inspection->ncr ? ['id' => $inspection->ncr->uuid, 'number' => $inspection->ncr->number, 'status' => $inspection->ncr->status] : null,
                'disposition_reason' => $inspection->disposition_reason,
                'disposed_at' => $inspection->disposed_at?->toIso8601String(),
                'inspected_at' => $inspection->inspected_at?->toIso8601String(),
                'notes' => $inspection->notes,
            ],
            'dispositions' => $user->can('dispose', $inspection) ? $this->quality->allowedDispositions($inspection) : [],
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($inspection, $user),
            'can' => [
                'record' => $user->can('record', $inspection),
                'dispose' => $user->can('dispose', $inspection),
            ],
        ]);
    }

    public function record(Request $request, string $uuid): RedirectResponse
    {
        $inspection = $this->find($uuid);
        $this->authorize('record', $inspection);

        $data = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:500'],
            'results.*.characteristic' => ['nullable', 'string'],
            'results.*.name' => ['nullable', 'string', 'max:255'],
            'results.*.sample_no' => ['nullable', 'integer', 'min:1'],
            'results.*.value' => ['nullable', 'numeric'],
            'results.*.passed' => ['nullable', 'boolean'],
            'results.*.notes' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $inspection = $this->quality->record($inspection, $data['results'], $request->user(), $data['notes'] ?? null);
        } catch (DomainException|PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($inspection->result === Inspection::FAILED ? 'error' : 'success',
            $inspection->result === Inspection::FAILED
                ? "Inspection {$inspection->number} failed. A non-conformance has been raised and the stock stays held until it is decided."
                : "Inspection {$inspection->number} passed.");
    }

    public function dispose(Request $request, string $uuid): RedirectResponse
    {
        $inspection = $this->find($uuid);
        $this->authorize('dispose', $inspection);

        $data = $request->validate([
            'decision' => ['required', Rule::in(Inspection::DISPOSITIONS)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->quality->dispose($inspection, $data['decision'], $data['reason'] ?? null, $request->user());
        } catch (DomainException|PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Inspection {$inspection->number}: ".strtolower(str_replace('_', ' ', $data['decision'])).'.');
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function find(string $uuid): Inspection
    {
        return Inspection::query()->with(['stockItem:id,code,name', 'lot:id,lot_number,expires_on', 'order:id,uuid,number', 'plan:id,code'])
            ->where('uuid', $uuid)->firstOrFail();
    }

    private function summarise(Inspection $i): array
    {
        return [
            'id' => $i->uuid, 'number' => $i->number, 'stage' => $i->stage, 'status' => $i->status, 'result' => $i->result,
            'disposition' => $i->disposition,
            'item_code' => $i->stockItem?->code, 'item_name' => $i->stockItem?->name,
            'lot' => $i->lot?->lot_number, 'qty' => (float) $i->qty, 'sample_size' => $i->sample_size,
            'plan_code' => $i->plan?->code,
            'order' => $i->order ? ['id' => $i->order->uuid, 'number' => $i->order->number] : null,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
