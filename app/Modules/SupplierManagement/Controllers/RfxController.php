<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Modules\SupplierManagement\Models\RfxInvitation;
use App\Modules\SupplierManagement\Models\RfxResponse;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\RfxEvaluationService;
use App\Modules\SupplierManagement\Services\RfxLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RfxController extends Controller
{
    public function __construct(
        private readonly RfxEvaluationService $evaluation,
        private readonly RfxLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only(['status']);

        $events = RfxEvent::withCount('responses')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/SupplierManagement/Sourcing/Index', [
            'events'  => $events,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Sourcing/Create', [
            'suppliers' => Supplier::active()->orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'type'           => 'required|in:rfq,rfp,rfi',
            'description'    => 'nullable|string',
            'closing_date'   => 'nullable|date',
            'supplier_ids'   => 'array',
            'supplier_ids.*' => 'integer|exists:suppliers,id',
        ]);

        $rfx = RfxEvent::create([
            'title'        => $data['title'],
            'type'         => $data['type'],
            'description'  => $data['description'] ?? null,
            'closing_date' => $data['closing_date'] ?? null,
            'status'       => 'draft',
            'created_by'   => Auth::id(),
        ]);

        foreach (array_unique($data['supplier_ids'] ?? []) as $supplierId) {
            $rfx->invitations()->create([
                'supplier_id' => $supplierId,
                'status'      => 'invited',
                'invited_at'  => now(),
            ]);
        }

        return redirect()
            ->route('suppliers.sourcing.show', $rfx->id)
            ->with('success', 'RFx event created.');
    }

    public function show(RfxEvent $rfx): Response
    {
        $this->authorize('view', Supplier::class);

        $rfx->load([
            'invitations.supplier:id,company_name',
            'responses.supplier:id,company_name',
            'awardedSupplier:id,company_name',
        ]);

        return Inertia::render('Modules/SupplierManagement/Sourcing/Show', [
            'rfx'         => $rfx,
            'invitations' => $rfx->invitations->map(fn (RfxInvitation $i) => [
                'id'           => $i->id,
                'supplier_id'  => $i->supplier_id,
                'supplier'     => $i->supplier?->company_name,
                'status'       => $i->status,
                'invited_at'   => $i->invited_at,
            ]),
            'responses' => $rfx->responses->map(fn (RfxResponse $r) => [
                'id'           => $r->id,
                'supplier_id'  => $r->supplier_id,
                'supplier'     => $r->supplier?->company_name,
                'amount'       => $r->amount,
                'currency'     => $r->currency,
                'proposal'     => $r->proposal,
                'ai_score'     => $r->ai_score,
                'ai_summary'   => $r->ai_summary,
                'submitted_at' => $r->submitted_at,
            ]),
            // The bid form's supplier list. Only invited suppliers can bid, so offering anything wider
            // invites a request the service will reject — and the old screen offered every supplier
            // in the tenant, which is how bids from outside the invitation list got recorded.
            'invitedSuppliers' => $rfx->invitations->map(fn (RfxInvitation $i) => [
                'id'   => $i->supplier_id,
                'name' => $i->supplier?->company_name,
            ])->values(),

            // What the screen is allowed to offer, so a disabled control explains itself rather than
            // failing on submit.
            'lifecycle' => [
                'open_for_bids' => $this->lifecycle->isOpenForBids($rfx),
                'can_publish'   => $rfx->status === 'draft',
                'can_close'     => $rfx->status === 'published',
                'can_award'     => $rfx->status === 'closed',
                'awardable'     => $this->lifecycle->awardableSuppliers($rfx),
                'awarded_by'    => $rfx->awardedBy?->person?->full_name ?? $rfx->awardedBy?->email,
                'awarded_at'    => optional($rfx->awarded_at)->toDateTimeString(),
                'award_reason'  => $rfx->award_reason,
            ],
        ]);
    }

    public function publish(RfxEvent $rfx): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $this->lifecycle->publish($rfx);

        return back()->with('success', __('supplier_management.rfx.published'));
    }

    public function close(RfxEvent $rfx): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $this->lifecycle->close($rfx);

        return back()->with('success', __('supplier_management.rfx.closed'));
    }

    public function recordResponse(Request $request, RfxEvent $rfx): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'amount'      => 'nullable|numeric|min:0',
            'currency'    => 'nullable|string|max:8',
            'proposal'    => 'nullable|string',
        ]);

        $this->lifecycle->recordResponse($rfx, $this->supplier($data['supplier_id']), $data);

        return back()->with('success', __('supplier_management.rfx.response_recorded'));
    }

    public function evaluate(RfxEvent $rfx): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $this->evaluation->evaluate($rfx);

        return back()->with('success', __('supplier_management.rfx.evaluated'));
    }

    public function award(Request $request, RfxEvent $rfx): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'supplier_id'  => 'required|integer',
            'award_reason' => 'nullable|string|max:2000',
        ]);

        $this->lifecycle->award(
            $rfx,
            $this->supplier($data['supplier_id']),
            (int) Auth::id(),
            $data['award_reason'] ?? null,
        );

        return back()->with('success', __('supplier_management.rfx.awarded'));
    }

    /**
     * Resolve a supplier id from the request through Eloquent, so the tenant scope applies.
     *
     * `exists:suppliers,id` cannot do this job: it is a raw query, the global scope never sees it,
     * and it passes for any company's supplier. Reading the record back is what turns a foreign id
     * into a 404 instead of a cross-tenant write — this module's purchase-order path already did it
     * this way, and the award, bid and invoice paths did not.
     */
    private function supplier(int $id): Supplier
    {
        return Supplier::findOrFail($id);
    }
}
