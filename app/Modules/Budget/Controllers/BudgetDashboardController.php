<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAlert;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Budget\Services\BudgetTrackingService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Budget landing page.
 *
 * The figures were already here; what was missing was anywhere to go from them. A dashboard that
 * reports "3 pending adjustments" and offers no way to reach them is a dashboard people read once.
 * The counts now carry the two things somebody would do about them — open the queue, open tracking.
 */
class BudgetDashboardController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetTrackingService $tracking,
        private readonly BudgetApprovalService $approvals,
    ) {
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Budget::class);

        // One aggregate for the whole tenant rather than five sums plus a per-budget loop.
        $positions = $this->tracking->positions();
        $rules = $this->tracking->rules();

        $totals = ['allocated' => 0.0, 'reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'overrides' => 0.0, 'available' => 0.0];

        foreach ($positions as $amounts) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $amounts[$key];
            }
        }

        $ceiling = $totals['allocated'] + $totals['overrides'];

        // Blocked is computed the same way the encumbrance check computes it, so the number on the
        // landing page and the refusal a buyer sees cannot disagree.
        $blocked = 0;

        foreach (Budget::query()->get() as $budget) {
            $amounts = $positions[$budget->id] ?? null;

            if ($amounts && $this->tracking->blockReason($budget, $amounts)) {
                $blocked++;
            }
        }

        return Inertia::render('Modules/Budget/Dashboard', [
            'stats' => [
                'budgets'    => count($positions),
                'allocated'  => round($totals['allocated'], 2),
                'reserved'   => round($totals['reserved'], 2),
                'committed'  => round($totals['committed'], 2),
                'actual'     => round($totals['actual'], 2),
                'available'  => round($totals['available'], 2),
                'utilisation' => $ceiling > 0
                    ? round((($totals['reserved'] + $totals['committed'] + $totals['actual']) / $ceiling) * 100, 1)
                    : 0,
                'overruns'      => count(array_filter($positions, fn ($a) => $a['available'] < 0)),
                'blocked'       => $blocked,
                'my_approvals'  => $this->approvals->pendingCountFor(),
                'open_alerts'   => BudgetAlert::open()->count(),
                'rules'         => $rules->count(),
            ],
            'byType' => Budget::selectRaw('expenditure_type, count(*) as n, coalesce(sum(allocated_amount),0) as allocated')
                ->groupBy('expenditure_type')->get(),
            'recent' => Budget::with('financialYear:id,name')->latest()->limit(8)
                ->get(['id', 'code', 'name', 'expenditure_type', 'allocated_amount', 'status', 'financial_year_id'])
                ->map(fn ($b) => [
                    'id' => $b->id, 'uuid' => $b->uuid, 'code' => $b->code, 'name' => $b->name,
                    'type' => $b->expenditure_type, 'allocated' => $b->allocated_amount,
                    'status' => $b->status, 'financial_year' => $b->financialYear?->name,
                ]),
        ]);
    }
}
