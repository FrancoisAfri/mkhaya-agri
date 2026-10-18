<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractApprovalStep;
use App\Modules\ContractManagement\Services\ContractApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ContractApprovalController extends Controller
{
    public function __construct(private readonly ContractApprovalService $approvals) {}

    public function index(): Response
    {
        $this->authorize('approve', Contract::class);

        $pending = $this->approvals->pending()->map(fn (Contract $c) => [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'title' => $c->title,
            'contract_value' => $c->contract_value,
            'contract_status' => $c->contract_status,
            'current_step_id' => $c->current_step_id,
            'type' => $c->type?->name,
        ])->values();

        $steps = ContractApprovalStep::with('users')
            ->orderBy('step_number')
            ->get()
            ->map(fn (ContractApprovalStep $s) => [
                'id' => $s->id,
                'step_number' => $s->step_number,
                'step_name' => $s->step_name,
                'max_amount' => $s->max_amount,
                'approval_type' => $s->approval_type,
                'role_id' => $s->role_id,
                'parent_step_id' => $s->parent_step_id,
                'status' => $s->status,
                'user_ids' => $s->users->pluck('user_id'),
            ]);

        return Inertia::render('Modules/ContractManagement/Approvals/Index', [
            'pending' => $pending,
            'steps' => $steps,
        ]);
    }

    public function approve(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('approve', Contract::class);

        $data = $request->validate([
            'step_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string'],
        ]);

        $step = ! empty($data['step_id']) ? ContractApprovalStep::find($data['step_id']) : null;

        $this->approvals->approve($contract, $step, Auth::id(), $data['comment'] ?? null);

        return back()->with('success', 'Contract approved.');
    }

    public function decline(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('approve', Contract::class);

        $data = $request->validate([
            'step_id' => ['nullable', 'integer'],
            'comment' => ['nullable', 'string'],
        ]);

        $step = ! empty($data['step_id']) ? ContractApprovalStep::find($data['step_id']) : null;

        $this->approvals->decline($contract, $step, Auth::id(), $data['comment'] ?? null);

        return back()->with('success', 'Contract declined.');
    }

    // ─── Step configuration ──────────────────────────────────────────────────

    public function stepsStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'step_number' => ['nullable', 'integer'],
            'step_name' => ['nullable', 'string', 'max:255'],
            'max_amount' => ['nullable', 'numeric'],
            'approval_type' => ['nullable', 'integer'],
            'role_id' => ['nullable', 'integer'],
            'parent_step_id' => ['nullable', 'integer'],
        ]);

        ContractApprovalStep::create($data);

        return back()->with('success', 'Approval step created.');
    }

    public function stepUpdate(Request $request, ContractApprovalStep $step): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $request->validate([
            'step_number' => ['nullable', 'integer'],
            'step_name' => ['nullable', 'string', 'max:255'],
            'max_amount' => ['nullable', 'numeric'],
            'approval_type' => ['nullable', 'integer'],
            'role_id' => ['nullable', 'integer'],
            'parent_step_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'integer'],
        ]);

        $step->update($data);

        return back()->with('success', 'Approval step updated.');
    }

    public function stepDestroy(ContractApprovalStep $step): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $step->delete();

        return back()->with('success', 'Approval step deleted.');
    }
}
