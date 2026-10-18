<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Requesting a change to an allocation.
 *
 * An adjustment is the only way the allocated amount moves after a budget is live — a direct PATCH
 * is refused by the update endpoint precisely so this path cannot be walked around. Approving one
 * happens on the approvals page, not here: a request that could be raised and granted in the same
 * two clicks by the same person is not a control.
 */
class BudgetAdjustmentController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetApprovalService $approvals,
    ) {
    }

    public function store(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'type'                  => ['required', 'in:increase,decrease,transfer'],
            'amount'                => ['required', 'numeric', 'min:0.01'],
            'transfer_to_budget_id' => ['nullable', 'required_if:type,transfer', 'integer', 'different:'.$budget->id, 'exists:budgets,id'],
            'reason'                => ['nullable', 'string', 'max:1000'],
        ]);

        $adjustment = DB::transaction(function () use ($budget, $data) {
            $adjustment = $this->budgets->adjustBudget(
                $budget, $data['type'], (float) $data['amount'],
                $data['reason'] ?? null, Auth::id(), $data['transfer_to_budget_id'] ?? null
            );

            $approval = $this->approvals->submit('adjustment', $adjustment, $budget, [
                'label'    => __('budget_management.approvals.label_adjustment', [
                    'type'   => $data['type'],
                    'amount' => number_format((float) $data['amount'], 2),
                    'code'   => $budget->code,
                ]),
                'amount'   => (float) $data['amount'],
                'currency' => $budget->currency,
                'note'     => $data['reason'] ?? null,
            ]);

            // Below every threshold anybody set: applied on submission. The end state is identical
            // to one that climbed a ladder, and the adjustment row still records who asked.
            if ($approval === null) {
                $this->budgets->approveAdjustment($adjustment, Auth::id());
            }

            return $adjustment->fresh();
        });

        return back()->with('success', $adjustment->status === 'approved'
            ? __('budget_management.flash.adjustment_applied')
            : __('budget_management.flash.adjustment_submitted'));
    }
}
