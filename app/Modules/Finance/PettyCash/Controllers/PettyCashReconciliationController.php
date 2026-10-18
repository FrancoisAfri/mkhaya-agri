<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Services\PettyCashPolicyGuard;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashReconciliationController extends Controller
{
    public function __construct(
        private PettyCashService     $service,
        private PettyCashPolicyGuard $policyGuard,
    ) {}

    public function index(Request $request): Response
    {
        $reconciliations = PettyCashReconciliation::with(['float', 'reconciledBy', 'approvedBy'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('float_id'), fn($q) => $q->where('float_id', $request->float_id))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Modules/Finance/PettyCash/Reconciliations/Index', [
            'reconciliations' => $reconciliations->through(fn($r) => [
                'id'           => $r->id,
                'uuid'         => $r->uuid,
                'float_name'   => $r->float?->name,
                'reconciled_by'=> $r->reconciledBy?->name,
                'period_start' => $r->period_start->toDateString(),
                'period_end'   => $r->period_end->toDateString(),
                'variance'     => $r->variance,
                'has_shortage' => $r->hasShortage(),
                'status'       => $r->status,
                'submitted_at' => $r->submitted_at?->toISOString(),
                'approved_at'  => $r->approved_at?->toISOString(),
            ]),
            'filters' => $request->only('status', 'float_id'),
            'floats'  => PettyCashFloat::where('status', 'active')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        /** @var \App\Core\User\Models\User $user */
        $user = auth()->user();

        $floats = PettyCashFloat::where('status', 'active')
            ->when(!$user->hasRole(['super admin', 'administrator', 'finance manager']),
                fn($q) => $q->where('custodian_id', $user->id)
            )
            ->get(['id', 'uuid', 'name', 'current_balance', 'float_limit', 'last_reconciled_at']);

        return Inertia::render('Modules/Finance/PettyCash/Reconciliations/Create', [
            'floats' => $floats->map(fn($f) => [
                'id'                 => $f->id,
                'name'               => $f->name,
                'current_balance'    => $f->current_balance,
                'last_reconciled_at' => $f->last_reconciled_at?->toDateString(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'float_id'              => 'required|exists:petty_cash_floats,id',
            'period_start'          => 'required|date',
            'period_end'            => 'required|date|after_or_equal:period_start',
            'opening_balance'       => 'required|numeric|min:0',
            'total_replenishments'  => 'required|numeric|min:0',
            'total_issued'          => 'required|numeric|min:0',
            'total_receipts'        => 'required|numeric|min:0',
            'cash_on_hand'          => 'required|numeric|min:0',
            'notes'                 => 'nullable|string',
        ]);

        $reconciliation = $this->service->reconcilePettyCash($data, $request->user()->id);

        // Policy guard throws ValidationException if custodian self-reconciliation is blocked.
        $this->policyGuard->forReconciliationSubmitted($reconciliation->load('float'), $request->user()->id);

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $file) {
                $this->service->uploadAttachment($reconciliation, $file, $request->user()->id, 'reconciliation_doc');
            }
        }

        return redirect()->route('petty-cash.reconciliations.show', $reconciliation->uuid)
            ->with('success', 'Reconciliation submitted.');
    }

    public function show(PettyCashReconciliation $reconciliation): Response
    {
        $reconciliation->load(['float', 'reconciledBy', 'approvedBy', 'attachments', 'policyViolations']);

        return Inertia::render('Modules/Finance/PettyCash/Reconciliations/Show', [
            'reconciliation' => [
                'id'                    => $reconciliation->id,
                'uuid'                  => $reconciliation->uuid,
                'float_name'            => $reconciliation->float?->name,
                'reconciled_by'         => $reconciliation->reconciledBy?->name,
                'approved_by'           => $reconciliation->approvedBy?->name,
                'period_start'          => $reconciliation->period_start->toDateString(),
                'period_end'            => $reconciliation->period_end->toDateString(),
                'opening_balance'       => $reconciliation->opening_balance,
                'total_replenishments'  => $reconciliation->total_replenishments,
                'total_issued'          => $reconciliation->total_issued,
                'total_receipts'        => $reconciliation->total_receipts,
                'cash_on_hand'          => $reconciliation->cash_on_hand,
                'closing_balance'       => $reconciliation->closing_balance,
                'variance'              => $reconciliation->variance,
                'has_shortage'          => $reconciliation->hasShortage(),
                'status'                => $reconciliation->status,
                'notes'                 => $reconciliation->notes,
                'rejection_reason'      => $reconciliation->rejection_reason,
                'submitted_at'          => $reconciliation->submitted_at?->toISOString(),
                'approved_at'           => $reconciliation->approved_at?->toISOString(),
            ],
            'attachments'      => $reconciliation->attachments->map(fn($a) => [
                'id'            => $a->id,
                'original_name' => $a->original_name,
                'url'           => $a->url,
            ]),
            'policyViolations' => $reconciliation->policyViolations->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'status'      => $v->status,
            ]),
        ]);
    }

    public function approve(Request $request, PettyCashReconciliation $reconciliation): RedirectResponse
    {
        abort_if($reconciliation->status !== 'submitted', 422, 'Only submitted reconciliations can be approved.');
        abort_if($reconciliation->reconciled_by === $request->user()->id, 403, 'You cannot approve your own reconciliation.');

        $reconciliation->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($reconciliation)
            ->event('approved')
            ->withProperties(['float_id' => $reconciliation->float_id, 'variance' => $reconciliation->variance])
            ->log("Petty cash reconciliation approved.");

        return back()->with('success', 'Reconciliation approved.');
    }

    public function reject(Request $request, PettyCashReconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        abort_if($reconciliation->status !== 'submitted', 422, 'Only submitted reconciliations can be rejected.');

        $reconciliation->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
        ]);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($reconciliation)
            ->event('rejected')
            ->withProperties(['float_id' => $reconciliation->float_id, 'reason' => $data['reason']])
            ->log("Petty cash reconciliation rejected.");

        return back()->with('success', 'Reconciliation rejected.');
    }
}
