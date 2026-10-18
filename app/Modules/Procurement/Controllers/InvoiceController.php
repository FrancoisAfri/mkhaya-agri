<?php

namespace App\Modules\Procurement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\InvoiceAiService;
use App\Modules\Procurement\Services\InvoiceMatchingService;
use App\Modules\Procurement\Services\InvoiceService;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    use FileStorage;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceAiService $ai,
        private readonly InvoiceMatchingService $matching,
        private readonly BudgetService $budgets,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $list = SupplierInvoice::query()
            ->with(['supplier:id,company_name', 'purchaseOrder:id,po_number'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('invoice_number', 'ilike', "%{$s}%")))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('match'), fn ($q, $v) => $q->where('match_status', $v))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (SupplierInvoice $i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'amount' => $i->amount,
                'currency' => $i->currency, 'status' => $i->status, 'match_status' => $i->match_status,
                'payment_status' => $i->payment_status, 'source' => $i->source,
                'supplier' => $i->supplier?->company_name, 'po_number' => $i->purchaseOrder?->po_number,
                'invoice_date' => $i->invoice_date?->toDateString(),
            ]);

        return Inertia::render('Modules/Procurement/Invoices/Index', [
            'invoices' => $list,
            'filters'  => $request->only('search', 'status', 'match'),
            'statuses' => ['pending', 'matched', 'exception', 'reconciled', 'paid', 'disputed'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', PurchaseRequest::class);

        return Inertia::render('Modules/Procurement/Invoices/Create', [
            'suppliers'      => Supplier::query()->orderBy('company_name')->get(['id', 'company_name']),
            'purchaseOrders' => PurchaseOrder::query()->whereIn('status', ['approved', 'sent'])
                ->latest()->limit(200)->get(['id', 'po_number', 'supplier_id', 'amount', 'budget_id']),
            'preselectPo'    => $request->integer('po') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);
        $data = $request->validate([
            'mode'              => ['required', 'in:from_po,manual'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'supplier_id'       => ['nullable', 'integer', 'exists:suppliers,id'],
            'budget_id'         => ['nullable', 'integer', 'exists:budgets,id'],
            'invoice_number'    => ['nullable', 'string', 'max:255'],
            'amount'            => ['nullable', 'numeric', 'min:0'],
            'invoice_date'      => ['nullable', 'date'],
            'due_date'          => ['nullable', 'date'],
            'file'              => ['nullable', 'file', 'max:10240'],
            'run_ai'            => ['nullable', 'boolean'],
        ]);

        if ($data['mode'] === 'from_po') {
            $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
            $invoice = $this->invoices->createFromPO($po, $data, Auth::id());
        } else {
            $invoice = $this->invoices->createManual($data, Auth::id());
        }

        if ($request->hasFile('file')) {
            $this->storeDocument($invoice, $request->file('file'));
        }
        if ($request->boolean('run_ai') && $invoice->hasDocument()) {
            $this->ai->extract($invoice, null, Auth::id());
        }

        return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice captured.');
    }

    public function show(SupplierInvoice $invoice): Response
    {
        $this->authorize('view', PurchaseRequest::class);

        $invoice->load(['supplier:id,company_name', 'purchaseOrder.items', 'purchaseOrder.goodsReceivedNotes', 'items', 'matchResults']);

        $budgetSnapshot = null;
        if ($invoice->budget_id && ($budget = Budget::find($invoice->budget_id))) {
            $budgetSnapshot = $this->budgets->amounts($budget);
        }

        $latestMatch = $invoice->matchResults->first();

        return Inertia::render('Modules/Procurement/Invoices/Show', [
            'invoice' => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
                'supplier' => $invoice->supplier?->company_name,
                'po' => $invoice->purchaseOrder ? ['id' => $invoice->purchaseOrder->id, 'po_number' => $invoice->purchaseOrder->po_number, 'amount' => $invoice->purchaseOrder->amount] : null,
                'amount' => $invoice->amount, 'subtotal' => $invoice->subtotal, 'tax_amount' => $invoice->tax_amount,
                'currency' => $invoice->currency, 'status' => $invoice->status, 'match_status' => $invoice->match_status,
                'payment_status' => $invoice->payment_status, 'source' => $invoice->source,
                'variance_amount' => $invoice->variance_amount, 'reconciled_at' => $invoice->reconciled_at?->toDateTimeString(),
                'invoice_date' => $invoice->invoice_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'ai_status' => $invoice->ai_status, 'ai_provider' => $invoice->ai_provider, 'ai_model' => $invoice->ai_model,
                'notes' => $invoice->notes, 'has_document' => $invoice->hasDocument(), 'file_name' => $invoice->file_name,
                'items' => $invoice->items->map(fn ($i) => [
                    'id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'quantity' => $i->quantity,
                    'unit_price' => $i->unit_price, 'line_total' => $i->line_total, 'tax' => $i->tax,
                ]),
                'grn_value' => (float) ($invoice->purchaseOrder?->goodsReceivedNotes->sum('received_value') ?? 0),
                'po_items' => $invoice->purchaseOrder?->items->map(fn ($i) => [
                    'name' => $i->name, 'quantity' => $i->quantity, 'received_qty' => $i->received_qty, 'unit_price' => $i->unit_price,
                ]) ?? [],
            ],
            'latestMatch'    => $latestMatch ? array_merge($latestMatch->toArray(), ['discrepancies' => $latestMatch->discrepancyList()]) : null,
            'budgetSnapshot' => $budgetSnapshot,
        ]);
    }

    public function uploadDocument(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $this->storeDocument($invoice, $request->file('file'));

        return back()->with('success', 'Invoice document uploaded.');
    }

    public function extract(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['text' => ['nullable', 'string']]);
        $invoice = $this->ai->extract($invoice, $data['text'] ?? null, Auth::id());

        return $invoice->ai_status === 'completed'
            ? back()->with('success', 'Invoice data extracted by AI.')
            : back()->with('error', 'AI extraction did not complete — check the document or paste the text.');
    }

    public function match(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:100']]);

        try {
            $result = $this->matching->match($invoice, (float) ($data['tolerance_pct'] ?? 2.0), Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($result->status === 'matched' ? 'success' : 'error',
            $result->status === 'matched' ? '3-way match passed.' : '3-way match flagged discrepancies.');
    }

    public function reconcile(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $data = $request->validate([
            'action' => ['required', 'in:accept,adjust,dispute'],
            'note'   => ['nullable', 'string'],
        ]);

        try {
            $this->matching->reconcile($invoice, $data['action'], $data['note'] ?? null, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invoice reconciled.');
    }

    public function markPaid(SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        try {
            $this->matching->markPaid($invoice, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Invoice marked as paid.');
    }

    public function updateItems(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'items'              => ['required', 'array'],
            'items.*.name'       => ['required', 'string', 'max:255'],
            'items.*.sku'        => ['nullable', 'string', 'max:255'],
            'items.*.quantity'   => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric'],
            'items.*.tax'        => ['nullable', 'numeric'],
        ]);
        $this->invoices->setItems($invoice, $data['items']);

        return back()->with('success', 'Invoice lines updated.');
    }

    public function downloadDocument(SupplierInvoice $invoice): \Illuminate\Http\Response
    {
        $this->authorize('view', PurchaseRequest::class);

        return $invoice->documentResponse();
    }

    private function storeDocument(SupplierInvoice $invoice, $file): void
    {
        $blob = $this->saveFileToDatabase($file);
        $invoice->fill([
            'file_name' => $blob['original_name'],
            'file_mime' => $blob['mime'], 'file_size' => $blob['size'],
        ]);

        $invoice->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        $invoice->save();
    }
}
