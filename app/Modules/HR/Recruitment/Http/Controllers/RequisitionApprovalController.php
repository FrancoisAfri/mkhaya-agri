<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Modules\HR\Recruitment\Actions\Requisition\ApproveRequisitionAction;
use App\Modules\HR\Recruitment\Actions\Requisition\RejectRequisitionAction;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Http\Requests\Requisition\ApproveRequisitionRequest;
use App\Modules\HR\Recruitment\Http\Requests\Requisition\RejectRequisitionRequest;
use App\Modules\HR\Recruitment\Http\Resources\RequisitionListResource;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionApprovalController
{
    public function pending(Request $request): Response
    {
        $requisitions = Requisition::with(['requestedBy'])
            ->where('status', RequisitionStatus::PendingApproval->value)
            ->latest('submitted_at')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total'    => Requisition::count(),
            'draft'    => Requisition::where('status', RequisitionStatus::Draft->value)->count(),
            'pending'  => Requisition::where('status', RequisitionStatus::PendingApproval->value)->count(),
            'approved' => Requisition::where('status', RequisitionStatus::Approved->value)->count(),
        ];

        return Inertia::render('Modules/Recruitment/Requisitions/PendingApproval', [
            'requisitions' => RequisitionListResource::collection($requisitions),
            'filters'      => $request->only('search', 'status'),
            'stats'        => $stats,
        ]);
    }

    public function approve(
        ApproveRequisitionRequest $request,
        Requisition $requisition,
        ApproveRequisitionAction $action,
    ): RedirectResponse {
        $action->execute($requisition, $request->user()->id, $request->input('notes'));

        return back()->with('success', 'Requisition approved.');
    }

    public function reject(
        RejectRequisitionRequest $request,
        Requisition $requisition,
        RejectRequisitionAction $action,
    ): RedirectResponse {
        $action->execute($requisition, $request->user()->id, $request->input('reason'));

        return back()->with('success', 'Requisition rejected.');
    }
}
