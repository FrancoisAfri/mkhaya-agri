<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetPhasing;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Finance\Accounting\Models\FinBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps Finance › Accounting's budgets in step with the budget module that controls the money.
 *
 * The product shipped two budgets. `fin_budgets` is an account-level plan for a fiscal year that
 * reports budget-versus-actual off the general ledger; this module holds the allocation people
 * actually spend against, with reservations, commitments, approvals and blocking rules on top. A
 * company running both had two numbers and no rule about which one was true.
 *
 * The rule: **this module is the record.** A company with only the accounting module carries on
 * exactly as before — nothing here runs. When both are live, every expense account in an accounting
 * budget is mirrored into a budget here, and control happens here.
 *
 * What is mirrored and what is not:
 *
 *   mirrored      one budget per expense account, its allocation, and its monthly phasing
 *   left alone    owners, alert rules, reservations, commitments, spend, the approval trail
 *
 * That split is the whole design. Accounting owns the plan; this module owns what happens to it.
 * Re-running the sync overwrites the first list and never touches the second, so a nightly job
 * cannot undo an afternoon of somebody's work.
 */
class AccountingBudgetSyncService
{
    public const SOURCE = 'finance_accounting';

    public function __construct(private readonly BudgetService $budgets)
    {
    }

    /**
     * Is there anything to sync into?
     *
     * False on a company that has not bought the budget module — in which case the accounting
     * budget is the only budget, which is a perfectly good place to be and not our business.
     */
    public function enabled(): bool
    {
        return Schema::hasTable('fin_budgets')
            && Schema::hasTable('budgets')
            && Schema::hasColumn('budgets', 'source_module')
            && DB::table('security_modules')->where('code_name', 'budget_management')->where('active', 1)->exists();
    }

    /**
     * Mirror one accounting budget.
     *
     * @return array{created:int, updated:int, skipped:int}
     */
    public function sync(FinBudget $finBudget): array
    {
        if (! $this->enabled()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $finBudget->loadMissing('lines.account');

        // Income lines are a revenue forecast, not money anybody spends. Mirroring them would
        // create budgets that can be "committed against", which is meaningless.
        $expenseLines = $finBudget->lines->filter(fn ($line) => ($line->account->type ?? null) === 'expense');

        if ($expenseLines->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'skipped' => $finBudget->lines->count()];
        }

        $year = $this->financialYearFor($finBudget);
        $created = $updated = 0;

        DB::transaction(function () use ($finBudget, $expenseLines, $year, &$created, &$updated) {
            foreach ($expenseLines->groupBy('account_id') as $accountId => $lines) {
                $account = $lines->first()->account;

                // period_no 0 is an annual figure; 1-12 are months. A budget may legitimately use
                // both — an annual base with a couple of months called out — so they are summed.
                $allocated = round((float) $lines->sum('amount'), 2);

                $existing = Budget::withoutGlobalScopes()
                    ->where('company_id', $finBudget->company_id)
                    ->where('source_module', self::SOURCE)
                    ->where('source_id', $finBudget->id)
                    ->where('source_ref', (string) $accountId)
                    ->first();

                $attributes = [
                    'name'              => trim(($account->code ?? '').' '.($account->name ?? 'Account '.$accountId)),
                    'financial_year_id' => $year?->id,
                    'allocated_amount'  => $allocated,
                    'status'            => $this->statusFor($finBudget),
                    'description'       => __('budget_management.sync.mirrored_from', ['budget' => $finBudget->name]),
                    'is_managed'        => true,
                ];

                if ($existing) {
                    $before = (float) $existing->allocated_amount;
                    $existing->update($attributes);

                    // The allocation moving upstream is a revision here, for the same reason an
                    // adjustment is: the number changed and the trail has to say why.
                    if (abs($before - $allocated) >= 0.01) {
                        $this->budgets->recordRevision(
                            $existing, $before, $allocated, 'adjustment', $finBudget->id,
                            __('budget_management.sync.revised_from', ['budget' => $finBudget->name]),
                        );
                    }

                    $budget = $existing;
                    $updated++;
                } else {
                    $budget = $this->budgets->createBudget($attributes + [
                        'expenditure_type' => 'opex',
                        'scope_type'       => 'category',
                        'scope_label'      => $account->name ?? null,
                        'source_module'    => self::SOURCE,
                        'source_id'        => $finBudget->id,
                        'source_ref'       => (string) $accountId,
                    ]);
                    $created++;
                }

                $this->syncPhasing($budget, $lines, $year);
            }
        });

        return ['created' => $created, 'updated' => $updated, 'skipped' => $finBudget->lines->count() - $expenseLines->count()];
    }

    /**
     * Mirror every accounting budget in the current tenant.
     *
     * @return array{created:int, updated:int, budgets:int}
     */
    public function syncAll(): array
    {
        if (! $this->enabled()) {
            return ['created' => 0, 'updated' => 0, 'budgets' => 0];
        }

        $totals = ['created' => 0, 'updated' => 0, 'budgets' => 0];

        FinBudget::with('lines.account')->chunkById(50, function ($chunk) use (&$totals) {
            foreach ($chunk as $finBudget) {
                $result = $this->sync($finBudget);
                $totals['created'] += $result['created'];
                $totals['updated'] += $result['updated'];
                $totals['budgets']++;
            }
        });

        return $totals;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A draft plan is not money anybody may spend.
     *
     * Accounting's own approval is treated as the approval — asking a company to approve the same
     * budget twice, once in each module, is how an integration gets switched off. What this module
     * adds on top is control, not a second signature.
     */
    private function statusFor(FinBudget $finBudget): string
    {
        return in_array($finBudget->status, ['approved', 'locked'], true) ? 'active' : 'draft';
    }

    /**
     * The financial year this fiscal year corresponds to, created if the tenant has not defined it.
     *
     * Matched on the calendar year the accounting module assumes, against whichever financial year
     * contains its first of January. A company on a March-to-February year gets the year that
     * actually spans it rather than a mismatched one.
     */
    private function financialYearFor(FinBudget $finBudget): ?FinancialYear
    {
        $anchor = Carbon::create($finBudget->fiscal_year, 1, 1);

        if (! $anchor) {
            return null;
        }

        $existing = FinancialYear::where('start_date', '<=', $anchor)->where('end_date', '>=', $anchor)->first();

        if ($existing) {
            return $existing;
        }

        return FinancialYear::create([
            'name'       => (string) $finBudget->fiscal_year,
            'start_date' => $anchor->toDateString(),
            'end_date'   => $anchor->copy()->endOfYear()->toDateString(),
            'status'     => 'active',
        ]);
    }

    /**
     * Carry the monthly plan across, so "budget vs actual" here compares like with like.
     *
     * Only monthly lines phase. An annual figure says nothing about when the money goes, and
     * spreading it evenly would invent a plan the accountant did not write.
     */
    private function syncPhasing(Budget $budget, $lines, ?FinancialYear $year): void
    {
        $monthly = $lines->filter(fn ($line) => (int) $line->period_no >= 1 && (int) $line->period_no <= 12);

        if ($monthly->isEmpty() || ! $year) {
            return;
        }

        $periods = BudgetPeriod::where('financial_year_id', $year->id)->orderBy('start_date')->get();

        if ($periods->isEmpty()) {
            return;
        }

        foreach ($monthly->groupBy('period_no') as $month => $group) {
            // The period whose range contains that month, rather than the nth period — a year that
            // starts in March has "period 1" in March, and month 4 is not its fourth period.
            $target = $periods->first(fn (BudgetPeriod $p) => (int) Carbon::parse($p->start_date)->month === (int) $month);

            if (! $target) {
                continue;
            }

            BudgetPhasing::updateOrCreate(
                ['budget_id' => $budget->id, 'budget_period_id' => $target->id],
                ['amount' => round((float) $group->sum('amount'), 2)],
            );
        }
    }
}
