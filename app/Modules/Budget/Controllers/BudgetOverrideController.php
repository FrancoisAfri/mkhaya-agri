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
 * Asking for permission to spend past the allocation.
 *
 * An approved override raises the ceiling the availability check measures against, which makes it
 * the single most consequential thing anybody can do to a budget without changing its allocation.
 * It is worth setting the threshold on an override flow lower than on an adjustment for exactly
 * that reason — the point of an override is that the budget has already run out.
 */
class BudgetOverrideController extends Controller
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $override = DB::transaction(function () use ($budget, $data) {
            $override = $this->budgets->requestBudgetOverride(
                $budget, (float) $data['amount'], $data['reason'], Auth::id()
            );

            $approval = $this->approvals->submit('override', $override, $budget, [
                'label'    => __('budget_management.approvals.label_override', [
                    'amount' => number_format((float) $data['amount'], 2),
                    'code'   => $budget->code,
                ]),
                'amount'   => (float) $data['amount'],
                'currency' => $budget->currency,
                'note'     => $data['reason'],
            ]);

            // Under every threshold the company set. Granting it here rather than leaving it
            // pending is the difference between a rule and a request that quietly never happens.
            if ($approval === null) {
                $this->budgets->approveBudgetOverride($override, Auth::id());
            }

            return $override->fresh();
        });

        return back()->with('success', $override->status === 'approved'
            ? __('budget_management.flash.override_granted')
            : __('budget_management.flash.override_submitted'));
    }
}
