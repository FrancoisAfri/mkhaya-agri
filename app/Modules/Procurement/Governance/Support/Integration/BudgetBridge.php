<?php

namespace App\Modules\Procurement\Governance\Support\Integration;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Governance\Services\Capabilities;
use Illuminate\Support\Facades\Schema;

/**
 * The seam between Procurement Governance and Budget Management (§2.3, §11).
 *
 * Everything about money in this module goes through here, for two reasons. The arithmetic that
 * defines "available budget" — allocated plus approved overrides, less reserved, committed and
 * actual — belongs to the Budget module and must not be re-derived here, because two definitions
 * of available budget in one product is a defect waiting to be found by a finance manager. And
 * every call has to survive Budget Management being absent: §11's whole argument is that a client
 * should not maintain six databases, but a tenant who has not bought the budget module must still
 * be able to plan procurement.
 *
 * So every method answers null rather than throwing or guessing, and callers render "not available"
 * instead of a zero. A zero would read as "no money", which is a different and much more alarming
 * claim than "we cannot see the budget from here".
 */
class BudgetBridge
{
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly BudgetService $budgets,
    ) {}

    public function isAvailable(): bool
    {
        return $this->capabilities->integratesWith('finance.budgets') && Schema::hasTable('budgets');
    }

    /**
     * @return array{allocated:float,reserved:float,committed:float,actual:float,overrides:float,available:float}|null
     */
    public function amounts(?int $budgetId): ?array
    {
        $budget = $this->find($budgetId);

        return $budget ? $this->budgets->amounts($budget) : null;
    }

    /** @return array{ok:bool,available:float,shortfall:float}|null */
    public function check(?int $budgetId, float $amount): ?array
    {
        $budget = $this->find($budgetId);

        return $budget ? $this->budgets->validateBudgetAvailability($budget, $amount) : null;
    }

    /**
     * Hold funds for a planned procurement. Returns the reservation id, or null when there is no
     * budget module to hold them in — in which case the plan is still perfectly valid, it simply
     * carries no financial hold.
     */
    public function reserve(?int $budgetId, float $amount, string $sourceType, int $sourceId, ?int $userId = null): ?int
    {
        $budget = $this->find($budgetId);

        if (! $budget || $amount <= 0) {
            return null;
        }

        try {
            return (int) $this->budgets->reserveBudget($budget, $amount, $sourceType, $sourceId, $userId)->id;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Release a hold — a cancelled plan line must not keep money locked up. */
    public function release(?int $reservationId): bool
    {
        if (! $reservationId || ! $this->isAvailable()) {
            return false;
        }

        try {
            $reservation = BudgetReservation::find($reservationId);

            if (! $reservation || $reservation->status !== 'active') {
                return false;
            }

            $this->budgets->releaseReservedBudget($reservation);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array{id:int,code:string|null,name:string,available:float|null}|null */
    public function summary(?int $budgetId): ?array
    {
        $budget = $this->find($budgetId);

        if (! $budget) {
            return null;
        }

        return [
            'id' => (int) $budget->id,
            'code' => $budget->code,
            'name' => (string) $budget->name,
            'available' => $this->budgets->amounts($budget)['available'],
        ];
    }

    /**
     * The statuses in which a budget may actually be spent against.
     *
     * Approving a budget does not leave it on 'approved' — BudgetService::approve() moves it to
     * 'active' — so filtering on 'approved' alone hides every budget that has been approved, which
     * is precisely the set a planner needs. This mirrors BudgetTrackingService's own definition
     * rather than inventing a second one.
     */
    private const SPENDABLE_STATUSES = ['active', 'approved'];

    /**
     * Budgets a user can pick from when planning. Tenant scoping comes from the Budget model's own
     * BelongsToCompany trait, so this does not re-implement it.
     *
     * @return list<array{id:int,code:string|null,name:string,available:float}>
     */
    public function selectable(?int $financialYearId = null): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        try {
            return Budget::query()
                ->when($financialYearId, fn ($q) => $q->where('financial_year_id', $financialYearId))
                ->whereIn('status', self::SPENDABLE_STATUSES)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'code', 'name', 'allocated_amount'])
                ->map(fn (Budget $b) => [
                    'id' => (int) $b->id,
                    'code' => $b->code,
                    'name' => (string) $b->name,
                    'available' => $this->budgets->amounts($b)['available'],
                ])
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function find(?int $budgetId): ?Budget
    {
        if (! $budgetId || ! $this->isAvailable()) {
            return null;
        }

        try {
            return Budget::find($budgetId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
