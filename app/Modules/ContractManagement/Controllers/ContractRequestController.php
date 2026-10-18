<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractRequest;
use App\Modules\ContractManagement\Models\ContractType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ContractRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $filters = $request->only('search', 'status');

        $requests = ContractRequest::with('type')
            ->filter($filters)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ContractRequest $r) => [
                'id' => $r->id,
                'request_number' => $r->request_number,
                'request_title' => $r->request_title,
                'status' => $r->status,
                'contract_value' => $r->contract_value,
                'criticality' => $r->criticality,
                'type' => $r->type?->name,
                'created_at' => $r->created_at?->toDateString(),
            ]);

        return Inertia::render('Modules/ContractManagement/Requests/Index', [
            'requests' => $requests,
            'filters' => $filters,
            'statuses' => ['pending', 'approved', 'rejected'],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Contract::class);

        return Inertia::render('Modules/ContractManagement/Requests/Create', [
            'types' => ContractType::where('status', true)->get(['id', 'name']),
            'units' => ['days', 'months', 'years'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $request->validate([
            'request_title' => ['required', 'string', 'max:255'],
            'contract_type_id' => ['nullable', 'integer'],
            'contract_length' => ['nullable', 'string', 'max:50'],
            'contract_length_unit' => ['nullable', 'string', 'max:20'],
            'criticality' => ['nullable', 'integer'],
            'contract_value' => ['nullable', 'numeric'],
            'summary' => ['nullable', 'string'],
            'motivation' => ['nullable', 'string'],
        ]);

        $data['requested_by'] = Auth::id();
        $data['status'] = 'pending';

        // TODO (deferred): auto-classification service (term/value/criticality/discretionary flags).
        $contractRequest = ContractRequest::create($data);

        return redirect()->route('contracts.requests.show', $contractRequest->id)
            ->with('success', 'Contract request submitted.');
    }

    public function show(int $id): Response
    {
        $this->authorize('view', Contract::class);

        $contractRequest = ContractRequest::with('type')->findOrFail($id);

        return Inertia::render('Modules/ContractManagement/Requests/Show', [
            'request' => [
                'id' => $contractRequest->id,
                'request_number' => $contractRequest->request_number,
                'request_title' => $contractRequest->request_title,
                'status' => $contractRequest->status,
                'contract_length' => $contractRequest->contract_length,
                'contract_length_unit' => $contractRequest->contract_length_unit,
                'criticality' => $contractRequest->criticality,
                'contract_value' => $contractRequest->contract_value,
                'summary' => $contractRequest->summary,
                'motivation' => $contractRequest->motivation,
                'recommendation' => $contractRequest->recommendation,
                'rejection_reason' => $contractRequest->rejection_reason,
                'type' => $contractRequest->type?->name,
            ],
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->authorize('approve', Contract::class);

        $data = $request->validate(['recommendation' => ['nullable', 'string']]);

        $contractRequest = ContractRequest::findOrFail($id);
        $contractRequest->update([
            'status' => 'approved',
            'recommendation' => $data['recommendation'] ?? null,
        ]);

        return back()->with('success', 'Request approved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->authorize('approve', Contract::class);

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $contractRequest = ContractRequest::findOrFail($id);
        $contractRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ]);

        return back()->with('success', 'Request rejected.');
    }
}
