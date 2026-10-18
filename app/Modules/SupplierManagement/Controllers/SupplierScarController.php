<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierScar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SupplierScarController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only(['status', 'severity', 'supplier_id']);

        $scars = SupplierScar::with('supplier:id,company_name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/SupplierManagement/Scar/Index', [
            'scars'     => $scars,
            'filters'   => $filters,
            'suppliers' => Supplier::orderBy('company_name')->get(['id', 'company_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'severity'    => 'required|in:low,medium,high,critical',
            'due_date'    => 'nullable|date',
            'assigned_to' => 'nullable|integer',
        ]);

        $data['raised_by'] = Auth::id();

        SupplierScar::create($data);

        return back()->with('success', 'SCAR raised.');
    }

    public function update(Request $request, SupplierScar $scar): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate([
            'status'            => 'required|in:open,investigating,action_plan,verifying,closed,rejected',
            'root_cause'        => 'nullable|string',
            'corrective_action' => 'nullable|string',
            'assigned_to'       => 'nullable|integer',
            'due_date'          => 'nullable|date',
        ]);

        $data['closed_at'] = $data['status'] === 'closed'
            ? ($scar->closed_at ?? now())
            : null;

        $scar->update($data);

        return back()->with('success', 'SCAR updated.');
    }

    public function destroy(SupplierScar $scar): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        $scar->delete();

        return back()->with('success', 'SCAR removed.');
    }
}
