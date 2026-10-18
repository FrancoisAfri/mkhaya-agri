<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCommitment;
use App\Modules\Budget\Models\BudgetConsumption;
use App\Modules\Budget\Support\BudgetPostResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one door every other module uses to tell the budget about money.
 *
 * Before this, the budget heard from Procurement and from nobody else. Payroll — usually the
 * largest operating line in the company — fleet, claims, petty cash and contracts all moved real
 * money through modules that had no way to say so. "Available" was therefore wrong on most real
 * budgets, and a blocking rule that only counted purchase orders would let a department run over
 * without ever tripping.
 *
 * Three properties make it safe to call from anywhere:
 *
 *   it never throws        a payroll run that has already been approved must not be unwound
 *                          because a budget was misconfigured. Every failure comes back as a
 *                          result and is logged.
 *   it posts at most once  a `reference` is enforced unique by the database, so a re-approved run
 *                          or a retried job cannot consume the budget twice.
 *   it degrades honestly   no matching budget returns `unmapped` rather than picking one. A number
 *                          that is wrong *and* believed is worse than one that is missing.
 *
 * Adding a module is one call:
 *
 *     app(BudgetLedgerBridge::class)->postSpend(
 *         sourceType: 'payroll_run',
 *         sourceId:   $run->id,
 *         amount:     (float) $run->total_cost,
 *         when:       $run->period_end,
 *         scope:      ['department_id' => $departmentId],
 *         reference:  "payroll_run:{$run->id}",
 *         note:       "Payroll {$run->name}",
 *     );
 */
class BudgetLedgerBridge
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetResolver $resolver,
    ) {
    }

    /**
     * Record money that has actually gone.
     *
     * Never refused, even when it takes the budget over. By the time payroll has run the money has
     * left; refusing to record it would leave the budget looking healthier than it is, which is the
     * one outcome worse than an overrun. The tracking sweep raises the alert instead, and the
     * resulting negative balance is what stops the *next* commitment.
     *
     * @param  array{budget_id?:int|null, department_id?:int|null, project_id?:int|null, category_id?:int|null, expenditure_type?:string|null}  $scope
     */
    public function postSpend(
        string $sourceType,
        ?int $sourceId,
        float $amount,
        ?Carbon $when = null,
        array $scope = [],
        ?string $reference = null,
        ?string $note = null,
    ): BudgetPostResult {
        return $this->post('spend', $sourceType, $sourceId, $amount, $when, $scope, $reference, $note);
    }

    /**
     * Lock funds against something signed but not yet invoiced — a lease, a contract schedule.
     *
     * Unlike spend, this *is* checked against the budget's rules: a commitment is a promise being
     * made now, and the whole point of a blocking rule is to stop it being made.
     */
    public function postCommitment(
        string $sourceType,
        ?int $sourceId,
        float $amount,
        ?Carbon $when = null,
        array $scope = [],
        ?string $reference = null,
        ?string $note = null,
    ): BudgetPostResult {
        return $this->post('commitment', $sourceType, $sourceId, $amount, $when, $scope, $reference, $note);
    }

    /**
     * Undo a commitment this bridge made — a cancelled contract, a reversed run.
     *
     * Matched by reference so the caller does not have to remember a ledger id.
     */
    public function release(string $reference): BudgetPostResult
    {
        if (! $this->enabled()) {
            return BudgetPostResult::disabled();
        }

        $commitment = BudgetCommitment::where('reference', $reference)->where('status', 'active')->first();

        if (! $commitment) {
            return BudgetPostResult::unmapped('No active commitment with that reference.');
        }

        $this->budgets->releaseCommitment($commitment);

        return BudgetPostResult::posted((int) $commitment->budget_id, (int) $commitment->id);
    }

    /**
     * Put a released commitment back, for when the thing that released it is undone.
     *
     * Releasing does not delete the commitment row, it marks it `released` — which means a later
     * `postCommitment` under the same reference finds it, calls it a duplicate, and quietly declines
     * to do anything. That is right for a retry and wrong for a reinstatement, and the difference
     * matters: an invoice captured against a purchase order releases that order's commitment, so
     * deleting the invoice has to restore it. Without this the order stays outstanding while its
     * money reads as available, and the company commits it a second time.
     *
     * Returns `unmapped` when there is nothing released to reinstate, so a caller can safely try.
     */
    public function reinstateCommitment(string $reference): BudgetPostResult
    {
        try {
            if (! $this->enabled()) {
                return BudgetPostResult::disabled();
            }

            $commitment = BudgetCommitment::where('reference', $reference)
                ->where('status', 'released')
                ->latest('id')
                ->first();

            if (! $commitment) {
                return BudgetPostResult::unmapped('No released commitment with that reference.');
            }

            $commitment->update(['status' => 'active']);

            return BudgetPostResult::posted((int) $commitment->budget_id, (int) $commitment->id);
        } catch (Throwable $e) {
            report($e);

            return BudgetPostResult::failed($e->getMessage());
        }
    }

    /**
     * Take back spend that should not have been recorded.
     *
     * For the ordinary correction: a mistyped fuel log deleted and re-entered. Without this the
     * budget carries the wrong figure and the right one, and the person who corrected it has no way
     * to see that it did not take.
     *
     * The consumption row is removed rather than reversed with a negative one. This ledger is a
     * position, not an append-only audit trail — the trail is on the source record, which is
     * soft-deleted and still says what it was. A negative line would make every report using
     * `count(*)` or an average quietly wrong instead.
     */
    public function reverseSpend(string $reference): BudgetPostResult
    {
        try {
            if (! $this->enabled()) {
                return BudgetPostResult::disabled();
            }

            $consumption = BudgetConsumption::where('reference', $reference)->first();

            if (! $consumption) {
                return BudgetPostResult::unmapped('Nothing was posted under that reference.');
            }

            $budgetId = (int) $consumption->budget_id;
            $ledgerId = (int) $consumption->id;
            $consumption->delete();

            return BudgetPostResult::posted($budgetId, $ledgerId);
        } catch (Throwable $e) {
            report($e);

            return BudgetPostResult::failed($e->getMessage());
        }
    }

    /** Whether the budget module is installed and the ledger can take a posting at all. */
    public function enabled(): bool
    {
        return Schema::hasTable('budgets')
            && Schema::hasColumn('budget_consumptions', 'reference')
            && DB::table('security_modules')->where('code_name', 'budget_management')->where('active', 1)->exists();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function post(
        string $kind,
        string $sourceType,
        ?int $sourceId,
        float $amount,
        ?Carbon $when,
        array $scope,
        ?string $reference,
        ?string $note,
    ): BudgetPostResult {
        try {
            if (! $this->enabled()) {
                return BudgetPostResult::disabled();
            }

            if ($amount <= 0) {
                // Not an error: a zero-value payroll run or a fully-credited claim is a real thing
                // that simply has nothing to tell the budget.
                return BudgetPostResult::unmapped('Nothing to post.');
            }

            // Checked before doing any work, so a retry is cheap and a re-approval is a no-op.
            if ($reference && $existing = $this->existing($kind, $reference)) {
                return BudgetPostResult::duplicate((int) $existing->budget_id, (int) $existing->id);
            }

            $budget = $this->resolver->resolve($scope, $when);

            if (! $budget) {
                $this->note($sourceType, $sourceId, 'no budget matched', $scope);

                return BudgetPostResult::unmapped('No live budget matches that department, project or category.');
            }

            return $kind === 'commitment'
                ? $this->commit($budget, $amount, $sourceType, $sourceId, $reference)
                : $this->consume($budget, $amount, $sourceType, $sourceId, $reference, $note);
        } catch (Throwable $e) {
            // The caller's own work has already succeeded. Surfacing this as an exception would
            // roll back a payroll run over a bookkeeping entry.
            report($e);

            return BudgetPostResult::failed($e->getMessage());
        }
    }

    private function consume(Budget $budget, float $amount, string $sourceType, ?int $sourceId, ?string $reference, ?string $note): BudgetPostResult
    {
        $consumption = DB::transaction(function () use ($budget, $amount, $sourceType, $sourceId, $reference, $note) {
            $row = $this->budgets->consumeBudget($budget, $amount, $sourceType, $sourceId, null, $note);

            if ($reference) {
                $row->update(['reference' => $reference]);
            }

            return $row;
        });

        return BudgetPostResult::posted((int) $budget->id, (int) $consumption->id);
    }

    private function commit(Budget $budget, float $amount, string $sourceType, ?int $sourceId, ?string $reference): BudgetPostResult
    {
        $commitment = DB::transaction(function () use ($budget, $amount, $sourceType, $sourceId, $reference) {
            $row = $this->budgets->commitBudget($budget, $amount, $sourceType, $sourceId);

            if ($reference) {
                $row->update(['reference' => $reference]);
            }

            return $row;
        });

        return BudgetPostResult::posted((int) $budget->id, (int) $commitment->id);
    }

    private function existing(string $kind, string $reference): BudgetConsumption|BudgetCommitment|null
    {
        return $kind === 'commitment'
            ? BudgetCommitment::where('reference', $reference)->first()
            : BudgetConsumption::where('reference', $reference)->first();
    }

    /**
     * Leave a trace when a real cost had nowhere to go.
     *
     * Silence here is how a company discovers at year end that half its spending was never counted.
     */
    private function note(string $sourceType, ?int $sourceId, string $why, array $scope): void
    {
        Log::warning('budget.posting_skipped', [
            'source' => $sourceType,
            'id'     => $sourceId,
            'why'    => $why,
            'scope'  => $scope,
        ]);
    }
}
