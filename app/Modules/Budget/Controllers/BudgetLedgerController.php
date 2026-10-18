<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Exceptions\BudgetBlockedException;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCommitment;
use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Budget\Services\BudgetTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Entering the ledger by hand: reserve, release, commit, consume.
 *
 * Procurement drives most of this automatically — a purchase request reserves, a purchase order
 * commits, an invoice consumes. But not every commitment a company makes goes through procurement.
 * A signed lease, a rates account, a subcontractor agreement drawn up in Word: these encumber a
 * budget just as firmly, and with no way to record them the available balance is a number that
 * flatters itself.
 *
 * Everything here goes through the same block check the automated path uses. A manual entry that
 * could quietly break a rule the automated one respects is a hole in the rule.
 */
class BudgetLedgerController extends Controller
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetTrackingService $tracking,
        private readonly BudgetApprovalService $approvals,
    ) {
    }

    /** Hold funds. Routed for approval when the tenant has designed a flow for reservations. */
    public function reserve(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0.01'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_id'   => ['nullable', 'integer'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->tracking->assertCanEncumber($budget, (float) $data['amount']);
        } catch (BudgetBlockedException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        $routed = $this->approvals->flowsFor('reservation')
            ->filter(fn ($f) => $f->steps->isNotEmpty() && $f->coversBudget($budget))
            ->isNotEmpty();

        $reservation = DB::transaction(function () use ($budget, $data, $routed) {
            $reservation = $this->budgets->reserveBudget(
                $budget,
                (float) $data['amount'],
                $data['source_type'] ?? 'manual',
                $data['source_id'] ?? null,
                Auth::id(),
                // Pending holds nothing: it is invisible to the balance until somebody approves it.
                $routed ? 'pending' : 'active',
            );

            if ($routed) {
                $request = $this->approvals->submit('reservation', $reservation, $budget, [
                    'label'    => __('budget_management.approvals.label_reservation', [
                        'amount' => number_format((float) $data['amount'], 2),
                        'code'   => $budget->code,
                    ]),
                    'amount'   => (float) $data['amount'],
                    'currency' => $budget->currency,
                    'note'     => $data['note'] ?? null,
                ]);

                // Every configured flow declined to route it — a threshold nobody cleared. It holds
                // the funds now rather than waiting for an approval that will never be asked for.
                if ($request === null) {
                    $reservation->update(['status' => 'active']);
                }
            }

            return $reservation;
        });

        return back()->with('success', $reservation->status === 'pending'
            ? __('budget_management.flash.reservation_submitted')
            : __('budget_management.flash.reservation_held'));
    }

    public function release(BudgetReservation $reservation): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        abort_if($reservation->status === 'committed', 422, __('budget_management.ledger.already_committed'));

        $this->budgets->releaseReservedBudget($reservation);

        return back()->with('success', __('budget_management.flash.reservation_released'));
    }

    /**
     * Lock funds against a supplier commitment.
     *
     * When a reservation is named it is converted rather than added to — reserving R100,000 and
     * then committing R100,000 without closing the hold would count the same money twice and take
     * R200,000 off the balance.
     */
    public function commit(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'reservation_id' => ['nullable', 'integer', 'exists:budget_reservations,id'],
            'source_type'    => ['nullable', 'string', 'max:100'],
            'source_id'      => ['nullable', 'integer'],
        ]);

        $reservation = $data['reservation_id']
            ? BudgetReservation::where('budget_id', $budget->id)->find($data['reservation_id'])
            : null;

        if ($data['reservation_id'] && ! $reservation) {
            return back()->withErrors(['reservation_id' => __('budget_management.ledger.reservation_not_on_budget')]);
        }

        // A conversion is not new spending: the hold it replaces is already counted, so only the
        // difference has to fit. Checking the gross amount would refuse a legitimate PO against a
        // budget that had correctly reserved for it.
        $incremental = (float) $data['amount'] - (float) ($reservation?->amount ?? 0);

        try {
            $this->tracking->assertCanEncumber($budget, max(0, $incremental));
        } catch (BudgetBlockedException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        $this->budgets->commitBudget(
            $budget,
            (float) $data['amount'],
            $data['source_type'] ?? 'manual',
            $data['source_id'] ?? null,
            $reservation && $reservation->status === 'active' ? $reservation : null,
        );

        return back()->with('success', __('budget_management.flash.commitment_locked'));
    }

    public function releaseCommitment(BudgetCommitment $commitment): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        abort_if($commitment->status === 'consumed', 422, __('budget_management.ledger.already_consumed'));

        $this->budgets->releaseCommitment($commitment);

        return back()->with('success', __('budget_management.flash.commitment_released'));
    }

    /**
     * Record what was actually spent.
     *
     * Deliberately not blocked. By the time an invoice exists the money has gone, and refusing to
     * record it would leave the budget looking healthier than it is — which is the one outcome
     * worse than an overrun. It raises an alert on the next sweep instead, and the negative balance
     * is what stops the next commitment.
     */
    public function consume(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate([
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'commitment_id' => ['nullable', 'integer', 'exists:budget_commitments,id'],
            'source_type'   => ['nullable', 'string', 'max:100'],
            'source_id'     => ['nullable', 'integer'],
            'note'          => ['nullable', 'string', 'max:500'],
        ]);

        $commitment = $data['commitment_id']
            ? BudgetCommitment::where('budget_id', $budget->id)->find($data['commitment_id'])
            : null;

        if ($data['commitment_id'] && ! $commitment) {
            return back()->withErrors(['commitment_id' => __('budget_management.ledger.commitment_not_on_budget')]);
        }

        $this->budgets->consumeBudget(
            $budget,
            (float) $data['amount'],
            $data['source_type'] ?? 'manual',
            $data['source_id'] ?? null,
            $commitment && $commitment->status === 'active' ? $commitment : null,
            $data['note'] ?? null,
        );

        return back()->with('success', __('budget_management.flash.spend_recorded'));
    }
}
