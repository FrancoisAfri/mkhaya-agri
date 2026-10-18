<?php

namespace App\Modules\Procurement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\Procurement\Models\ProcurementRequisitionAttachment;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\RequisitionService;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    use FileStorage;

    public function __construct(private readonly RequisitionService $requisitions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $list = ProcurementRequisition::query()
            ->with('budget:id,code')
            ->filter($request->only('search', 'status', 'method'))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (ProcurementRequisition $r) => [
                'id' => $r->id, 'requisition_number' => $r->requisition_number, 'title' => $r->title,
                'method' => $r->procurement_method, 'status' => $r->status,
                'estimated_total' => $r->estimated_total, 'currency' => $r->currency,
                'budget' => $r->budget?->code, 'reservation_confirmed' => $r->reservation_confirmed,
            ]);

        return Inertia::render('Modules/Procurement/Requisitions/Index', [
            'requisitions' => $list,
            'filters'      => $request->only('search', 'status', 'method'),
            'statuses'     => ['open', 'sourcing', 'quoting', 'awarded', 'po_created', 'closed', 'cancelled'],
        ]);
    }

    public function convert(PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);

        try {
            $req = $this->requisitions->convertFromRequest($purchaseRequest, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('requisitions.show', $req->id)->with('success', 'Requisition created from request.');
    }

    public function show(ProcurementRequisition $requisition): Response
    {
        $this->authorize('view', PurchaseRequest::class);

        $requisition->load(['items', 'attachments', 'history', 'budget', 'purchaseRequest:id,request_number']);

        return Inertia::render('Modules/Procurement/Requisitions/Show', [
            'requisition' => $requisition,
            'officers'    => $this->officers(),
            'suppliers'   => Supplier::query()->orderByDesc('is_preferred')->orderBy('company_name')
                ->get(['id', 'company_name', 'is_preferred']),
            'methods'     => ['rfq', 'direct', 'marketplace', 'contract'],
        ]);
    }

    public function assignOfficer(Request $request, ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['officer_id' => ['required', 'integer', 'exists:users,id']]);
        $this->requisitions->assignOfficer($requisition, $data['officer_id'], Auth::id());

        return back()->with('success', 'Officer assigned.');
    }

    public function confirmMethod(Request $request, ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['method' => ['required', 'in:rfq,direct,marketplace,contract']]);
        $this->requisitions->confirmMethod($requisition, $data['method'], Auth::id());

        return back()->with('success', 'Procurement method set.');
    }

    public function confirmReservation(ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $res = $this->requisitions->confirmReservation($requisition, Auth::id());

        return $res['ok'] ? back()->with('success', $res['message']) : back()->with('error', $res['message']);
    }

    public function createRfq(Request $request, ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'supplier_ids'   => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['integer', 'exists:suppliers,id'],
        ]);
        $this->requisitions->createRfq($requisition, $data['supplier_ids'], Auth::id());

        return back()->with('success', 'RFQ issued to selected suppliers.');
    }

    public function chooseSupplier(Request $request, ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate(['supplier_id' => ['required', 'integer', 'exists:suppliers,id']]);
        $this->requisitions->chooseSupplier($requisition, $data['supplier_id'], Auth::id());

        return back()->with('success', 'Supplier selected.');
    }

    public function uploadAttachment(Request $request, ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $blob = $this->saveFileToDatabase($request->file('file'));
        $attachment = $requisition->attachments()->make([
            'file_name' => $blob['original_name'],
            'file_mime' => $blob['mime'], 'file_size' => $blob['size'], 'uploaded_by' => Auth::id(),
        ]);

        $attachment->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        $attachment->save();

        return back()->with('success', 'Document attached.');
    }

    public function downloadAttachment(ProcurementRequisitionAttachment $attachment): \Illuminate\Http\Response
    {
        $this->authorize('view', PurchaseRequest::class);

        return $attachment->documentResponse();
    }

    public function cancel(ProcurementRequisition $requisition): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $this->requisitions->cancel($requisition, Auth::id());

        return back()->with('success', 'Requisition cancelled.');
    }

    /** Users who can act as procurement officers (best-effort name resolution). */
    private function officers(): array
    {
        if (Schema::hasTable('hr_people')) {
            return DB::table('users')
                ->leftJoin('hr_people', 'hr_people.user_id', '=', 'users.id')
                ->select('users.id', DB::raw("trim(concat(coalesce(hr_people.first_name,''),' ',coalesce(hr_people.surname,''))) as name"), 'users.email')
                ->orderBy('name')->limit(200)->get()
                ->map(fn ($u) => ['id' => $u->id, 'name' => trim($u->name) ?: $u->email])->all();
        }

        return DB::table('users')->select('id', 'email')->orderBy('email')->limit(200)->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->email])->all();
    }
}
