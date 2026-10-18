<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetApprovalDelegation;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Handing your budget approvals to somebody while you are away.
 *
 * Deliberately something a person does for themselves rather than an administrator does to them.
 * The approver is the one who knows they are on leave on Thursday, and a control that needs a
 * support ticket to use is a control nobody uses — they forward their password instead, which is
 * the outcome this exists to prevent.
 *
 * An administrator can still cover a vacancy or somebody who left without arranging it, which is
 * the case delegation-by-self cannot serve.
 */
class BudgetDelegationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->rules($request);

        // Delegating on somebody else's behalf is an administrative act; delegating your own is not.
        if ((int) $data['from_user_id'] !== (int) Auth::id()) {
            $this->authorize('update', Budget::class);
        }

        if ((int) $data['from_user_id'] === (int) $data['to_user_id']) {
            return back()->withErrors(['to_user_id' => __('budget_management.delegation.to_yourself')]);
        }

        if (BudgetApprovalDelegation::hasOverlap($data['from_user_id'], $data['starts_on'], $data['ends_on'] ?? null)) {
            // Two live delegations for the same window is two people believing they hold it, and
            // an audit trail that cannot say which of them was meant to.
            return back()->withErrors(['starts_on' => __('budget_management.delegation.overlaps')]);
        }

        BudgetApprovalDelegation::create($data + ['created_by' => Auth::id(), 'is_active' => true]);

        return back()->with('success', __('budget_management.flash.delegation_saved'));
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $delegation = BudgetApprovalDelegation::where('uuid', $uuid)->firstOrFail();

        // Ending your own early is yours to do; ending somebody else's is not.
        if ((int) $delegation->from_user_id !== (int) Auth::id()) {
            $this->authorize('update', Budget::class);
        }

        // Soft-deleted rather than switched off, so the trail still explains why a decision three
        // weeks ago was made by somebody who is not on the flow.
        $delegation->delete();

        return back()->with('success', __('budget_management.flash.delegation_ended'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function rules(Request $request): array
    {
        return $request->validate([
            'from_user_id' => ['required', 'integer', 'exists:users,id'],
            'to_user_id'   => ['required', 'integer', 'exists:users,id'],
            'starts_on'    => ['required', 'date'],
            // Open-ended is allowed, for a vacancy rather than a week of leave.
            'ends_on'      => ['nullable', 'date', 'after_or_equal:starts_on'],
            'subject_type' => ['nullable', Rule::in(BudgetApprovalFlow::SUBJECT_TYPES)],
            'max_amount'   => ['nullable', 'numeric', 'min:0'],
            'reason'       => ['nullable', 'string', 'max:500'],
        ]);
    }
}
