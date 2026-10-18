<?php

namespace App\Modules\Procurement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\ProcurementPolicyException;
use App\Modules\Procurement\Models\ProcurementPolicyRule;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProcurementPolicyController extends Controller
{
    public function index(): Response
    {
        $this->authorize('update', PurchaseRequest::class);

        return Inertia::render('Modules/Procurement/Policies/Index', [
            'rules'      => ProcurementPolicyRule::orderBy('type')->orderBy('sort_order')->get(),
            'exceptions' => ProcurementPolicyException::where('status', 'pending')->latest()->get(),
            'types'      => [
                'approval_threshold', 'quotation_threshold', 'item_restriction',
                'supplier_restriction', 'self_approval', 'budget_required',
                'emergency_justification', 'duplicate', 'split',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'type'       => ['required', 'string', 'max:50'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'role'       => ['nullable', 'string', 'max:100'],
            'params'     => ['nullable', 'array'],
            'active'     => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        ProcurementPolicyRule::create($data);

        return back()->with('success', 'Policy rule added.');
    }

    public function update(Request $request, ProcurementPolicyRule $rule): RedirectResponse
    {
        $this->authorize('update', PurchaseRequest::class);
        $data = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'type'       => ['sometimes', 'required', 'string', 'max:50'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'role'       => ['nullable', 'string', 'max:100'],
            'params'     => ['nullable', 'array'],
            'active'     => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);
        $rule->update($data);

        return back()->with('success', 'Policy rule updated.');
    }

    public function destroy(ProcurementPolicyRule $rule): RedirectResponse
    {
        $this->authorize('delete', PurchaseRequest::class);
        $rule->delete();

        return back()->with('success', 'Policy rule removed.');
    }

    public function approveException(ProcurementPolicyException $exception): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $exception->update(['status' => 'approved', 'approved_by' => Auth::id(), 'acted_at' => now()]);

        return back()->with('success', 'Exception approved.');
    }

    public function rejectException(ProcurementPolicyException $exception): RedirectResponse
    {
        $this->authorize('approve', PurchaseRequest::class);
        $exception->update(['status' => 'rejected', 'approved_by' => Auth::id(), 'acted_at' => now()]);

        return back()->with('success', 'Exception rejected.');
    }
}
