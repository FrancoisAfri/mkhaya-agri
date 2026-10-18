<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\CreateAssetsFromPurchaseRequestAction;
use App\Modules\Assets\Actions\CreatePurchaseRequestAction;
use App\Modules\Assets\Actions\SubmitPurchaseRequestAction;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetPurchaseRequest;
use App\Modules\Assets\Services\AssetPolicyGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    public function __construct(private readonly AssetPolicyGuard $policyGuard) {}

    public function index(Request $request): Response
    {
        $requests = AssetPurchaseRequest::with(['requestedBy', 'category'])
            ->when($request->filled('status'),   fn($q) => $q->where('status', $request->status))
            ->when($request->filled('priority'), fn($q) => $q->where('priority', $request->priority))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'draft'     => AssetPurchaseRequest::where('status', 'draft')->count(),
            'submitted' => AssetPurchaseRequest::where('status', 'submitted')->count(),
            'approved'  => AssetPurchaseRequest::where('status', 'approved')->count(),
            'ordered'   => AssetPurchaseRequest::where('status', 'ordered')->count(),
        ];

        return Inertia::render('Modules/Assets/Procurement/Index', [
            'requests' => [
                'data' => $requests->map(fn($r) => [
                    'uuid'                 => $r->uuid,
                    'request_number'       => $r->request_number,
                    'item_description'     => $r->item_description,
                    'quantity'             => $r->quantity,
                    'estimated_total_cost' => $r->estimated_total_cost,
                    'priority'             => ['value' => $r->priority->value, 'label' => $r->priority->label(), 'color' => $r->priority->color()],
                    'status'               => ['value' => $r->status->value, 'label' => $r->status->label(), 'color' => $r->status->color()],
                    'requestedBy'          => $r->requestedBy ? ['name' => $r->requestedBy->name] : null,
                    'created_at'           => $r->created_at?->toISOString(),
                ]),
                'meta'  => ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'total' => $requests->total()],
                'links' => ['prev' => $requests->previousPageUrl(), 'next' => $requests->nextPageUrl()],
            ],
            'stats'    => $stats,
            'filters'  => $request->only('status', 'priority'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/Procurement/Create', [
            'categories' => AssetCategory::where('status', 1)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreatePurchaseRequestAction $action): RedirectResponse
    {
        $data = $request->validate([
            'item_description'    => 'required|string|max:500',
            'asset_category_id'   => 'nullable|integer|exists:asset_categories,id',
            'quantity'            => 'required|integer|min:1|max:1000',
            'estimated_unit_cost' => 'required|numeric|min:0.01',
            'justification'       => 'required|string|max:5000',
            'priority'            => 'required|string|in:low,medium,high,urgent',
        ]);

        $pr = $action->execute($data, $request->user()->id);

        return redirect()->route('assets.procurement.show', $pr->uuid)
            ->with('success', 'Purchase request created.');
    }

    public function show(AssetPurchaseRequest $purchaseRequest): Response
    {
        $purchaseRequest->load(['requestedBy', 'category', 'approval', 'items.asset']);

        return Inertia::render('Modules/Assets/Procurement/Show', [
            'purchase_request' => $purchaseRequest,
        ]);
    }

    public function update(Request $request, AssetPurchaseRequest $purchaseRequest): RedirectResponse
    {
        abort_unless($purchaseRequest->status->value === 'draft', 422, 'Only draft requests can be edited.');

        $data = $request->validate([
            'item_description'    => 'sometimes|string|max:500',
            'quantity'            => 'sometimes|integer|min:1',
            'estimated_unit_cost' => 'sometimes|numeric|min:0.01',
            'justification'       => 'sometimes|string|max:5000',
            'priority'            => 'sometimes|string|in:low,medium,high,urgent',
        ]);

        if (isset($data['quantity']) || isset($data['estimated_unit_cost'])) {
            $qty  = $data['quantity']            ?? $purchaseRequest->quantity;
            $cost = $data['estimated_unit_cost'] ?? $purchaseRequest->estimated_unit_cost;
            $data['estimated_total_cost'] = $qty * $cost;
        }

        $purchaseRequest->update($data);

        return back()->with('success', 'Request updated.');
    }

    public function destroy(AssetPurchaseRequest $purchaseRequest): RedirectResponse
    {
        abort_unless($purchaseRequest->status->value === 'draft', 422, 'Only draft requests can be deleted.');
        $purchaseRequest->delete();

        return redirect()->route('assets.procurement.index')->with('success', 'Request deleted.');
    }

    public function submit(Request $request, AssetPurchaseRequest $purchaseRequest, SubmitPurchaseRequestAction $action): RedirectResponse
    {
        $this->policyGuard->forPurchaseRequested(
            $purchaseRequest->requested_by ?? $request->user()->id,
            $purchaseRequest->asset_category_id ?? 0,
            (float) ($purchaseRequest->estimated_unit_cost * $purchaseRequest->quantity),
            $purchaseRequest->quantity,
            $request->user()->id,
        );

        $action->execute($purchaseRequest, $request->user()->id);

        return back()->with('success', 'Request submitted for approval.');
    }

    public function deliver(Request $request, AssetPurchaseRequest $purchaseRequest): RedirectResponse
    {
        abort_unless(in_array($purchaseRequest->status->value, ['approved', 'ordered']), 422, 'Request must be approved or ordered to mark as delivered.');

        $data = $request->validate([
            'actual_unit_cost'      => 'nullable|numeric|min:0',
            'delivered_at'          => 'required|date',
            'vendor_name'           => 'nullable|string|max:255',
            'vendor_reference'      => 'nullable|string|max:255',
            'delivery_notes'        => 'nullable|string|max:2000',
        ]);

        $actualUnit  = $data['actual_unit_cost'] ?? $purchaseRequest->estimated_unit_cost;
        $actualTotal = $actualUnit * $purchaseRequest->quantity;

        $purchaseRequest->update(array_merge($data, [
            'status'            => 'delivered',
            'actual_unit_cost'  => $actualUnit,
            'actual_total_cost' => $actualTotal,
        ]));

        return back()->with('success', 'Request marked as delivered.');
    }

    public function createAssets(Request $request, AssetPurchaseRequest $purchaseRequest, CreateAssetsFromPurchaseRequestAction $action): RedirectResponse
    {
        $assets = $action->execute($purchaseRequest, $request->user()->id);

        return back()->with('success', count($assets) . ' asset(s) created from request.');
    }
}
