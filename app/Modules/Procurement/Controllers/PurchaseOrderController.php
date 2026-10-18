<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly BudgetService $budgets,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $list = PurchaseOrder::query()
            ->with('supplier:id,company_name')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) =>
                $w->where('po_number', 'ilike', "%{$s}%")->orWhere('title', 'ilike', "%{$s}%")))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('received'), fn ($q, $v) => $q->where('received_status', $v))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (PurchaseOrder $po) => [
                'id' => $po->id, 'po_number' => $po->po_number, 'title' => $po->title,
                'supplier' => $po->supplier?->company_name, 'amount' => $po->amount,
                'currency' => $po->currency, 'status' => $po->status,
                'received_status' => $po->received_status, 'source' => $po->source,
                'order_date' => $po->order_date?->toDateString(),
            ]);

        return Inertia::render('Modules/Procurement/PurchaseOrders/Index', [
            'orders'   => $list,
            'filters'  => $request->only('search', 'status', 'received'),
            'statuses' => ['draft', 'approved', 'sent', 'cancelled'],
        ]);
    }

    public function generate(ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);

        try {
            $po = $this->orders->generateFromRequisition($requisition, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-orders.show', $po->id)->with('success', 'Purchase order generated and budget committed.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('view', PurchaseRequest::class);

        $purchaseOrder->load([
            'supplier:id,company_name', 'items', 'deliveryNotes', 'amendments',
            'goodsReceivedNotes.items', 'assetLinks', 'inventoryLinks',
            'requisition:id,requisition_number', 'budget:id,code,name',
        ]);

        $budgetSnapshot = null;
        if ($purchaseOrder->budget_id && ($budget = Budget::find($purchaseOrder->budget_id))) {
            $budgetSnapshot = $this->budgets->amounts($budget);
        }

        return Inertia::render('Modules/Procurement/PurchaseOrders/Show', [
            'order'          => $purchaseOrder,
            'budgetSnapshot' => $budgetSnapshot,
        ]);
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $this->orders->approve($purchaseOrder, Auth::id());

        return back()->with('success', 'Purchase order approved.');
    }

    public function send(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $this->orders->send($purchaseOrder, Auth::id());

        return back()->with('success', 'Purchase order issued to supplier.');
    }

    public function storeDelivery(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'supplier_ref' => ['nullable', 'string', 'max:255'],
            'delivered_at' => ['nullable', 'date'],
            'notes'        => ['nullable', 'string'],
        ]);
        $this->orders->captureDeliveryNote($purchaseOrder, $data, Auth::id());

        return back()->with('success', 'Delivery note captured.');
    }

    public function storeGrn(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'notes'              => ['nullable', 'string'],
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.item_id'    => ['required', 'integer'],
            'lines.*.accepted_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejected_qty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.condition'  => ['nullable', 'in:good,damaged'],
            'lines.*.note'       => ['nullable', 'string'],
        ]);

        $this->orders->createGRN($purchaseOrder, $data['lines'], Auth::id(), $data['notes'] ?? null);

        return back()->with('success', 'Goods received — budget actuals updated.');
    }

    public function amend(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'type'       => ['required', 'in:value,quantity,other'],
            'new_amount' => ['nullable', 'numeric', 'min:0'],
            'detail'     => ['nullable', 'string'],
            'reason'     => ['nullable', 'string'],
        ]);
        $this->orders->amend($purchaseOrder, $data, Auth::id());

        return back()->with('success', 'Purchase order amended.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $this->orders->cancel($purchaseOrder, Auth::id());

        return back()->with('success', 'Purchase order cancelled and commitment released.');
    }
}
