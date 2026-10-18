<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierKycApproval;
use App\Modules\SupplierManagement\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SupplierKycController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(): Response
    {
        $this->authorize('approve', Supplier::class);

        $approvals = SupplierKycApproval::with('supplier')
            ->where('status', 'Pending')
            ->latest()
            ->get();

        return Inertia::render('Modules/SupplierManagement/Kyc/Index', [
            'approvals' => $approvals,
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, approved: true);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, approved: false);
    }

    /**
     * Record a KYC decision, by somebody other than the person who asked for it.
     *
     * Approval used to require only the `suppliers.approve` permission, so one user could raise a
     * supplier, enter its banking details, and clear its own submission without anybody else seeing
     * the record. That is the single-operator path every payments fraud takes, and the permission
     * alone cannot close it — the check has to compare two people.
     *
     * A rejection also has to say why. "Declined" with no reason is a message the supplier has to
     * reply to, and the buyer answers by hand every time.
     */
    private function decide(Request $request, int $id, bool $approved): RedirectResponse
    {
        $this->authorize('approve', Supplier::class);

        $data = $request->validate([
            'comment' => $approved ? 'nullable|string|max:2000' : 'required|string|max:2000',
        ]);

        $approval = SupplierKycApproval::findOrFail($id);

        if ($approval->status !== 'Pending') {
            return back()->withErrors([
                'status' => __('supplier_management.kyc.errors.already_decided', [
                    'status' => $approval->status,
                ]),
            ]);
        }

        if ($approval->requested_by && (int) $approval->requested_by === (int) Auth::id()) {
            return back()->withErrors([
                'status' => __('supplier_management.kyc.errors.self_review'),
            ]);
        }

        $approval->update([
            'status'     => $approved ? 'Approved' : 'Rejected',
            'decided_by' => Auth::id(),
            'decided_at' => now(),
            'comment'    => $data['comment'] ?? null,
        ]);

        // Read the supplier back through Eloquent so the tenant scope applies, and so the decision
        // notification has a record to address rather than an id.
        if ($approval->supplier_id && $supplier = Supplier::find($approval->supplier_id)) {
            $supplier->update(['is_active' => $approved ? 1 : 2]);

            $this->suppliers->notifyDecision(
                $supplier,
                $approved ? 'approved' : 'refused',
                $data['comment'] ?? null,
            );
        }

        return back()->with('success', $approved
            ? __('supplier_management.kyc.approved')
            : __('supplier_management.kyc.rejected'));
    }
}
