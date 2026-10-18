<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\ProcurementApproval;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Services\ProcurementApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementApprovalController extends Controller
{
    public function __construct(private readonly ProcurementApprovalService $approvals) {}

    /** Approvals inbox — pending purchase-request approvals with their current step. */
    public function index(): Response
    {
        $this->authorize('approve', PurchaseRequest::class);

        $pending = ProcurementApproval::query()
            ->where('source_type', PurchaseRequest::class)
            ->where('status', 'pending')
            ->with('steps')
            ->latest()
            ->get()
            ->map(function (ProcurementApproval $a) {
                $req = PurchaseRequest::find($a->source_id);
                $step = $a->steps->firstWhere('step', $a->current_step);

                return [
                    'id' => $a->id,
                    'request_id' => $a->source_id,
                    'request_number' => $req?->request_number,
                    'requester_id' => $req?->requester_id,
                    'amount' => $req?->estimated_total,
                    'currency' => $req?->currency,
                    'current_step' => $a->current_step,
                    'total_steps' => $a->total_steps,
                    'step_name' => $step?->name,
                    'step_role' => $step?->role,
                ];
            });

        return Inertia::render('Modules/Procurement/Approvals/Index', ['pending' => $pending]);
    }

    public function approve(Request $request, ProcurementApproval $approval): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $res = $this->approvals->approveStep($approval, (int) Auth::id(), $request->input('comment'));

        return $res['ok'] ? back()->with('success', $res['message']) : back()->with('error', $res['message']);
    }

    public function reject(Request $request, ProcurementApproval $approval): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $res = $this->approvals->rejectStep($approval, (int) Auth::id(), $request->input('comment'));

        return $res['ok'] ? back()->with('success', $res['message']) : back()->with('error', $res['message']);
    }

    public function escalate(Request $request, ProcurementApproval $approval): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $this->approvals->escalate($approval, $request->input('reason'), $request->input('escalate_to'));

        return back()->with('success', 'Approval escalated.');
    }
}
