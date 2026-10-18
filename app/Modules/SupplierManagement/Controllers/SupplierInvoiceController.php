<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\BankingGate;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use App\Modules\SupplierManagement\Services\InvoiceMatchingService;
use App\Modules\SupplierManagement\Services\SupplierBudgetPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceMatchingService $matching,
        private readonly SupplierBudgetPoster $budget,
        private readonly BankingGate $banking,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only(['status', 'supplier_id']);

        $invoices = SupplierInvoice::with(['supplier:id,company_name', 'purchaseOrder:id,po_number'])
            ->status($filters['status'] ?? null)
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/SupplierManagement/Spend/InvoiceIndex', [
            'invoices'  => $invoices,
            'filters'   => $filters,
            'suppliers' => Supplier::orderBy('company_name')->get(['id', 'company_name']),
            'orders'    => PurchaseOrder::orderByDesc('id')->limit(500)->get(['id', 'po_number', 'supplier_id']),
            'contracts' => $this->contractOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'invoice_number'    => 'required|string|max:255',
            'supplier_id'       => 'required|integer',
            'contract_id'       => 'nullable|integer',
            'purchase_order_id' => 'nullable|integer',
            'amount'            => 'required|numeric|min:0',
            'currency'          => 'nullable|string|max:8',
            'invoice_date'      => 'nullable|date',
            'due_date'          => 'nullable|date',
        ]);

        // Read both foreign keys back through Eloquent so the tenant scope applies. `exists:` is a
        // raw query and passes for any company's row, which is how another tenant's supplier ended
        // up on this tenant's payables.
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $order    = ($data['purchase_order_id'] ?? null) ? PurchaseOrder::findOrFail($data['purchase_order_id']) : null;

        $this->matching->assertNotDuplicate($supplier->id, $data['invoice_number']);

        $data['currency']   = $data['currency'] ?? 'ZAR';
        $data['created_by'] = Auth::id();

        $invoice = SupplierInvoice::create($data + $this->matching->evaluate(
            $order,
            (float) $data['amount'],
            $supplier->id,
        ));

        $this->budget->recordInvoice($invoice);

        return back()->with('success', $invoice->match_status === 'variance'
            ? __('supplier_management.invoices.captured_with_variance', [
                'variance' => number_format((float) $invoice->variance_amount, 2),
                'po'       => $order?->po_number,
            ])
            : __('supplier_management.invoices.captured'));
    }

    public function update(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'status'            => 'required|in:pending,matched,paid,disputed',
            'contract_id'       => 'nullable|integer',
            'purchase_order_id' => 'nullable|integer',
            'amount'            => 'nullable|numeric|min:0',
            'due_date'          => 'nullable|date',
        ]);

        $order = ($data['purchase_order_id'] ?? null)
            ? PurchaseOrder::findOrFail($data['purchase_order_id'])
            : $invoice->purchaseOrder;

        // Judge the invoice as it will be after this edit, not as it was before it.
        //
        // The variance guard has to run against the incoming figures: an over-billed invoice whose
        // amount is being corrected to the agreed one is no longer a variance, and blocking the
        // payment on the state it is being edited out of leaves no way to fix and pay from the
        // screen — which sends a real amount to the till in two steps or not at all.
        $match = $this->matching->evaluate(
            $order,
            (float) ($data['amount'] ?? $invoice->amount),
            (int) $invoice->supplier_id,
        );

        // Paying anyway is what would make the flag decorative, so this stays a refusal — but only
        // when the invoice is still over its order once the edit is applied.
        if ($data['status'] === 'paid' && $match['match_status'] === 'variance') {
            return back()->withErrors([
                'status' => __('supplier_management.invoices.errors.pay_with_open_variance'),
            ]);
        }

        // The account the money would go to has to belong to the supplier the invoice is from.
        // A verified holder-name mismatch is the signature of payment redirection, so it stops the
        // payment outright; an unverified account only stops it where the tenant has asked for
        // verification to be mandatory.
        if ($data['status'] === 'paid' && $invoice->supplier) {
            $blocked = $this->banking->blockReason($invoice->supplier);

            if ($blocked !== null) {
                return back()->withErrors([
                    'status' => __('supplier_management.invoices.errors.pay_with_unverified_banking', ['reason' => $blocked]),
                ]);
            }
        }

        $invoice->update(array_filter(
            $data,
            fn ($value, $key) => $key === 'status' || $value !== null,
            ARRAY_FILTER_USE_BOTH
        ) + $match);

        return back()->with('success', __('supplier_management.invoices.updated'));
    }

    public function destroy(SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        // Take the spend off the budget before the row goes, while the reference still resolves.
        $this->budget->reverseInvoice($invoice);

        $invoice->delete();

        return back()->with('success', __('supplier_management.invoices.removed'));
    }

    /**
     * Active contracts for the optional contract linkage select. Guarded read-only
     * query against the contracts table (owned by another module). Returns [] if absent.
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
