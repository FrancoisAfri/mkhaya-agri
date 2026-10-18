<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\PurchaseOrderWorkflow;
use App\Modules\SupplierManagement\Services\SupplierBudgetPoster;
use App\Modules\SupplierManagement\Services\SupplierComplianceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderWorkflow $orders,
        private readonly SupplierBudgetPoster $budget,
        private readonly SupplierComplianceService $compliance,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only(['status', 'supplier_id']);

        $orders = PurchaseOrder::with('supplier:id,company_name')
            ->status($filters['status'] ?? null)
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/SupplierManagement/Spend/PoIndex', [
            'orders'    => $orders,
            'filters'   => $filters,
            'suppliers' => Supplier::orderBy('company_name')->get(['id', 'company_name']),
            'contracts' => $this->contractOptions(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Spend/PoIndex', [
            'orders'    => PurchaseOrder::with('supplier:id,company_name')->latest()->paginate(15),
            'filters'   => [],
            'suppliers' => Supplier::orderBy('company_name')->get(['id', 'company_name']),
            'contracts' => $this->contractOptions(),
            'openCreate' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'contract_id' => 'nullable|integer',
            'title'       => 'required|string|max:255',
            'amount'      => 'required|numeric|min:0',
            'currency'    => 'nullable|string|max:8',
            'order_date'  => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        // Only active (in-good-standing) suppliers may receive new POs. A supplier on
        // hold/pending/refused (e.g. after a portal bank-detail change flags them) is
        // blocked until reactivated — closes the pay-a-suspended-supplier gap.
        $supplier = Supplier::findOrFail($data['supplier_id']);
        if ((int) $supplier->is_active !== 1) {
            return back()->withErrors([
                'supplier_id' => __('supplier_management.orders.errors.supplier_not_active', [
                    'status' => __('supplier_management.statuses.'.((int) $supplier->is_active)),
                ]),
            ]);
        }

        // Compliance is checked here, not only overnight. `procurement:expiry-alerts` suspends a
        // supplier whose mandatory document has lapsed, but it runs once a day — between a
        // certificate expiring and that job running, this was the gap orders went through.
        $lapsed = $this->compliance->lapsedDocuments($supplier);

        if ($lapsed->isNotEmpty()) {
            return back()->withErrors([
                'supplier_id' => __('supplier_management.orders.errors.expired_documents', [
                    'documents' => $lapsed->implode(', '),
                ]),
            ]);
        }

        $data['currency']   = $data['currency'] ?? 'ZAR';
        $data['created_by'] = Auth::id();

        $order = PurchaseOrder::create($data);

        $this->budget->syncPurchaseOrder($order);

        return back()->with('success', __('supplier_management.orders.created'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', Supplier::class);


        $data = $request->validate([
            'status'      => 'required|in:draft,approved,received,closed,cancelled',
            'contract_id' => 'nullable|integer',
            'amount'      => 'nullable|numeric|min:0',
            'order_date'  => 'nullable|date',
            'notes'       => 'nullable|string',
        ]);

        $previous = (string) $purchaseOrder->status;

        if (! $this->orders->canTransition($previous, $data['status'])) {
            return back()->withErrors([
                'status' => __('supplier_management.orders.errors.invalid_transition', [
                    'from' => __("supplier_management.orders.statuses.{$previous}"),
                    'to'   => __("supplier_management.orders.statuses.{$data['status']}"),
                ]),
            ]);
        }

        // Approving is the moment the money is promised, so it is the moment that needs a name
        // against it — and someone other than the person who raised the order.
        if ($data['status'] === 'approved') {
            if ((int) $purchaseOrder->created_by === (int) Auth::id()) {
                return back()->withErrors([
                    'status' => __('supplier_management.orders.errors.self_approval'),
                ]);
            }

            $this->authorize('approve', Supplier::class);

            $data['approved_by'] = Auth::id();
            $data['approved_at'] = now();
        }

        $purchaseOrder->update(array_filter(
            $data,
            fn ($value, $key) => $key === 'status' || $value !== null,
            ARRAY_FILTER_USE_BOTH
        ));

        $this->budget->syncPurchaseOrder($purchaseOrder->refresh(), $previous);

        return back()->with('success', __('supplier_management.orders.updated'));
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        // Free the committed funds before the row goes. A deleted order that keeps its commitment is
        // budget nobody can spend and nobody can find.
        $this->budget->syncPurchaseOrder(
            tap($purchaseOrder)->forceFill(['status' => 'cancelled']),
            previousStatus: $purchaseOrder->getOriginal('status'),
        );

        $purchaseOrder->delete();

        return back()->with('success', __('supplier_management.orders.removed'));
    }

    /**
     * Active contracts (id + label + supplier) pulled directly from the contracts
     * table when it exists. Contracts live in another module so this is a guarded,
     * read-only DB query rather than an Eloquent relation. Returns [] if absent.
     *
     * @return array<int, array{id:int, label:string, supplier_id:int|null}>
     */
    private function contractOptions(): array
    {
        if (! Schema::hasTable('contracts')) {
            return [];
        }

        return DB::table('contracts')
            ->whereNull('deleted_at')
            ->whereNotIn('contract_status', ['cancelled', 'expired', 'terminated'])
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'contract_number', 'title', 'supplier_id'])
            ->map(fn ($c) => [
                'id'          => (int) $c->id,
                'label'       => trim(($c->contract_number ? $c->contract_number . ' — ' : '') . ($c->title ?? '')),
                'supplier_id' => $c->supplier_id !== null ? (int) $c->supplier_id : null,
            ])
            ->all();
    }
}
