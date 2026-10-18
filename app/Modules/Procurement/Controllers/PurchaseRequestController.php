<?php

namespace App\Modules\Procurement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Procurement\Models\ProcurementPolicyViolation;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\PurchaseRequestAttachment;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    use FileStorage;

    public function __construct(private readonly PurchaseRequestService $requests) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $list = PurchaseRequest::query()
            ->with('budget:id,code,name')
            ->filter($request->only('search', 'status', 'urgency', 'requester_id'))
            ->when($request->boolean('mine'), fn ($q) => $q->where('requester_id', Auth::id()))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (PurchaseRequest $r) => [
                'id' => $r->id, 'request_number' => $r->request_number, 'status' => $r->status,
                'urgency' => $r->urgency, 'is_emergency' => $r->is_emergency,
                'estimated_total' => $r->estimated_total, 'currency' => $r->currency,
                'budget' => $r->budget?->code, 'needed_by' => $r->needed_by?->toDateString(),
                'created_at' => $r->created_at?->toDateString(),
            ]);

        return Inertia::render('Modules/Procurement/Requests/Index', [
            'requests' => $list,
            'filters'  => $request->only('search', 'status', 'urgency', 'mine'),
            'statuses' => $this->statuses(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', PurchaseRequest::class);

        return Inertia::render('Modules/Procurement/Requests/Create', [
            'budgets'    => Budget::where('status', 'active')->orderBy('name')->get(['id', 'code', 'name', 'currency']),
            'categories' => \App\Modules\Budget\Models\BudgetCategory::where('status', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        $data = $request->validate([
            'budget_id'         => ['nullable', 'integer', 'exists:budgets,id'],
            'department'        => ['nullable', 'string', 'max:255'],
            'project_code'      => ['nullable', 'string', 'max:255'],
            'urgency'           => ['required', 'in:low,normal,high,emergency'],
            'justification'     => ['nullable', 'string'],
            'delivery_location' => ['nullable', 'string', 'max:255'],
            'needed_by'         => ['nullable', 'date'],
            'currency'          => ['nullable', 'string', 'max:3'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.name'      => ['required', 'string', 'max:255'],
            'items.*.quantity'  => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.item_type' => ['nullable', 'in:catalog,custom,service'],
            'items.*.budget_category_id' => ['nullable', 'integer'],
        ]);

        $data['is_emergency'] = ($data['urgency'] ?? null) === 'emergency';
        $items = $data['items'];
        unset($data['items']);

        $pr = $this->requests->create($data, Auth::id());
        foreach ($items as $item) {
            $pr->items()->create([
                'item_type' => $item['item_type'] ?? 'custom',
                'name' => $item['name'], 'description' => $item['description'] ?? null,
                'budget_category_id' => $item['budget_category_id'] ?? null,
                'quantity' => $item['quantity'], 'unit_price' => $item['unit_price'],
            ]);
        }
        $pr->recalcTotal();

        return redirect()->route('purchase-requests.show', $pr->id)->with('success', 'Request created. Review and submit.');
    }

    public function show(PurchaseRequest $purchaseRequest): Response
    {
        $this->authorize('view', PurchaseRequest::class);

        $purchaseRequest->load(['items', 'attachments', 'history', 'budget', 'approval.steps']);

        return Inertia::render('Modules/Procurement/Requests/Show', [
            'request'    => $purchaseRequest,
            'violations' => ProcurementPolicyViolation::where('source_type', PurchaseRequest::class)
                ->where('source_id', $purchaseRequest->id)->latest()->get(),
            'statuses'   => $this->statuses(),
        ]);
    }

    public function submit(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);

        $result = $this->requests->submit($purchaseRequest, Auth::id());

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    public function cancel(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $this->requests->cancel($purchaseRequest, Auth::id());

        return back()->with('success', 'Request cancelled.');
    }

    public function uploadAttachment(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $blob = $this->saveFileToDatabase($request->file('file'));
        $attachment = $purchaseRequest->attachments()->make([
            'file_name' => $blob['original_name'],
            'file_mime' => $blob['mime'], 'file_size' => $blob['size'], 'uploaded_by' => Auth::id(),
        ]);

        $attachment->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        $attachment->save();

        return back()->with('success', 'Document uploaded.');
    }

    public function downloadAttachment(PurchaseRequestAttachment $attachment): \Illuminate\Http\Response
    {
        $this->authorize('view', PurchaseRequest::class);

        return $attachment->documentResponse();
    }

    public function destroy(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('delete', PurchaseRequest::class);
        $purchaseRequest->delete();

        return redirect()->route('purchase-requests.index')->with('success', 'Request deleted.');
    }

    private function statuses(): array
    {
        return ['draft', 'pending_approval', 'validation_failed', 'approved', 'rejected', 'cancelled', 'converted'];
    }
}
