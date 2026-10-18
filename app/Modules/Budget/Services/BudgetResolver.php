<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\FinancialYear;
use Illuminate\Support\Carbon;

/**
 * Which budget does this cost belong to?
 *
 * Every module that spends money knows what it spent, when, and roughly on whose behalf — a
 * department, a project, a cost centre. None of them know which budget row that maps to, and none
 * of them should: that mapping is a budgeting concern and it changes every financial year.
 *
 * The rules, most specific first:
 *
 *   1. an explicit budget id, when the caller genuinely knows one
 *   2. the department or project the cost was incurred for
 *   3. a named category
 *   4. the company's general budget for that year
 *
 * A miss returns null rather than guessing. Posting a payroll run against whatever budget happened
 * to sort first would be worse than not posting it: the number would be wrong *and* believed.
 */
class BudgetResolver
{
    /**
     * @param  array{budget_id?:int|null, department_id?:int|null, project_id?:int|null, category_id?:int|null, expenditure_type?:string|null}  $scope
     */
    public function resolve(array $scope, ?Carbon $when = null): ?Budget
    {
        $when ??= now();

        if (! empty($scope['budget_id'])) {
            return $this->spendable()->find($scope['budget_id']);
        }

        $year = $this->financialYearFor($when);

        foreach ($this->candidates($scope) as [$scopeType, $scopeId]) {
            $budget = $this->spendable()
                ->when($year, fn ($q) => $q->where('financial_year_id', $year->id))
                ->where('scope_type', $scopeType)
                ->when($scopeId !== null, fn ($q) => $q->where('scope_id', $scopeId))
                ->when(! empty($scope['expenditure_type']), fn ($q) => $q->where('expenditure_type', $scope['expenditure_type']))
                ->orderBy('id')
                ->first();

            if ($budget) {
                return $budget;
            }
        }

        return null;
    }

    /**
     * The financial year a date falls in.
     *
     * Falls back to the one flagged current, because a company that has not defined next year yet
     * still spends money in it and the alternative is silently posting nothing.
     */
    public function financialYearFor(Carbon $when): ?FinancialYear
    {
        return FinancialYear::where('start_date', '<=', $when->toDateString())
            ->where('end_date', '>=', $when->toDateString())
            ->first()
            ?? FinancialYear::where('is_current', true)->first();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The scopes to try, in order of how specifically they describe the cost.
     *
     * @return array<int, array{0: string, 1: int|null}>
     */
    private function candidates(array $scope): array
    {
        $candidates = [];

        if (! empty($scope['project_id'])) {
            $candidates[] = ['project', (int) $scope['project_id']];
        }

        if (! empty($scope['department_id'])) {
            $candidates[] = ['department', (int) $scope['department_id']];
        }

        if (! empty($scope['category_id'])) {
            $candidates[] = ['category', (int) $scope['category_id']];
        }

        // The catch-all every company has, or should. Deliberately last.
        $candidates[] = ['general', null];

        return $candidates;
    }

    /**
     * Only a live budget can take a posting.
     *
     * A draft is a plan nobody has agreed to; a closed one belongs to a year that has been reported.
     * Posting into either would move a number somebody has already signed off.
     */
    private function spendable()
    {
        return Budget::query()->whereIn('status', ['active', 'approved']);
    }
}
