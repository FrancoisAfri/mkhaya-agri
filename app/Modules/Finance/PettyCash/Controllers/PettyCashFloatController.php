<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashFloatController extends Controller
{
    public function __construct(private PettyCashService $service) {}

    public function index(Request $request): Response
    {
        $floats = PettyCashFloat::with(['custodian'])
            ->withCount(['requests', 'reconciliations'])
            ->when($request->filled('search'), fn($q) => $q->where('name', 'ilike', "%{$request->search}%"))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Modules/Finance/PettyCash/Floats/Index', [
            'floats'  => $floats->through(fn($f) => [
                'id'                 => $f->id,
                'uuid'               => $f->uuid,
                'name'               => $f->name,
                'custodian'          => $f->custodian?->name ?? 'Unassigned',
                'current_balance'    => $f->current_balance,
                'float_limit'        => $f->float_limit,
                'utilization_pct'    => $f->utilizationPercentage(),
                'needs_replenishment'=> $f->needsReplenishment(),
                'status'             => $f->status,
                'requests_count'     => $f->requests_count,
                'reconciliations_count'=> $f->reconciliations_count,
                'last_reconciled_at' => $f->last_reconciled_at?->toDateString(),
                'created_at'         => $f->created_at->toISOString(),
            ]),
            'filters'    => $request->only('search', 'status'),
            'custodians' => User::with('person:id,user_id,first_name,surname')
                ->get(['id'])
                ->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Finance/PettyCash/Floats/Create', [
            'custodians' => User::with('person:id,user_id,first_name,surname')->get(['id'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:255',
            'description'             => 'nullable|string',
            'custodian_id'            => 'required|exists:users,id',
            'float_limit'             => 'required|numeric|min:1',
            'replenishment_threshold' => 'nullable|numeric|min:0',
            'currency'                => 'nullable|string|size:3',
            'notes'                   => 'nullable|string',
        ]);

        $float = $this->service->createPettyCashFloat($data, $request->user()->id);

        return redirect()->route('petty-cash.floats.show', $float->uuid)
            ->with('success', 'Float created successfully.');
    }

    public function show(PettyCashFloat $float): Response
    {
        $float->load('custodian');

        $recentTransactions = $float->transactions()
            ->with('performedBy')
            ->orderByDesc('performed_at')
            ->limit(20)
            ->get()
            ->map(fn($t) => [
                'id'               => $t->id,
                'type'             => $t->transaction_type,
                'amount'           => $t->amount,
                'balance_before'   => $t->balance_before,
                'balance_after'    => $t->balance_after,
                'description'      => $t->description,
                'performed_by'     => $t->performedBy?->name,
                'performed_at'     => $t->performed_at->toISOString(),
            ]);

        $pendingRequests = $float->requests()
            ->with('requestor')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'id'               => $r->id,
                'uuid'             => $r->uuid,
                'reference_number' => $r->reference_number,
                'requestor'        => $r->requestor?->name,
                'purpose'          => $r->purpose,
                'amount'           => $r->amount_requested,
                'status'           => $r->status,
                'requested_at'     => $r->requested_at?->toISOString(),
            ]);

        return Inertia::render('Modules/Finance/PettyCash/Floats/Show', [
            'float' => [
                'id'                     => $float->id,
                'uuid'                   => $float->uuid,
                'name'                   => $float->name,
                'description'            => $float->description,
                'custodian'              => ['id' => $float->custodian?->id, 'name' => $float->custodian?->name ?? 'Unassigned'],
                'current_balance'        => $float->current_balance,
                'float_limit'            => $float->float_limit,
                'opening_balance'        => $float->opening_balance,
                'replenishment_threshold'=> $float->replenishment_threshold,
                'currency'               => $float->currency,
                'status'                 => $float->status,
                'utilization_pct'        => $float->utilizationPercentage(),
                'needs_replenishment'    => $float->needsReplenishment(),
                'last_reconciled_at'     => $float->last_reconciled_at?->toDateString(),
                'notes'                  => $float->notes,
                'created_at'             => $float->created_at->toISOString(),
            ],
            'recentTransactions' => $recentTransactions,
            'pendingRequests'    => $pendingRequests,
            'custodians'         => User::with('person:id,user_id,first_name,surname')->get(['id'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name]),
        ]);
    }

    public function update(Request $request, PettyCashFloat $float): RedirectResponse
    {
        $data = $request->validate([
            'name'                    => 'sometimes|string|max:255',
            'description'             => 'nullable|string',
            'custodian_id'            => 'sometimes|exists:users,id',
            'replenishment_threshold' => 'nullable|numeric|min:0',
            'status'                  => 'sometimes|in:active,inactive,suspended',
            'notes'                   => 'nullable|string',
        ]);

        $float->update($data);

        return back()->with('success', 'Float updated.');
    }

    public function replenish(Request $request, PettyCashFloat $float): RedirectResponse
    {
        $data = $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'reference' => 'nullable|string|max:100',
        ]);

        $this->service->replenishPettyCashFloat($float, $data['amount'], $request->user()->id, $data['reference'] ?? null);

        return back()->with('success', "Float replenished by R{$data['amount']}.");
    }
}
