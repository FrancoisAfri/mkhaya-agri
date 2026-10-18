<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\EtiClaim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * How much of the Employment Tax Incentive somebody has actually used.
 *
 * The twenty-four months are months *claimed*, not months employed. Deriving them from a start date
 * — which is what the bureau engine did — answers a different question and gets the common cases
 * wrong: a rehire, an employment gap, a month nobody claimed, or an employee arriving with claims
 * already made elsewhere. It also stopped the incentive at month 25 of tenure, so an employer who
 * missed three months lost three months of benefit they were entitled to.
 *
 * Engine-agnostic on purpose. The entitlement belongs to the person, so an employee who moves from a
 * bureau client onto the in-house payroll carries their used months with them rather than starting
 * again at one.
 */
class EtiClaimService
{
    /** How many qualifying months this employee has already used. */
    public function claimedMonths(string $type, int $employeeId, ?string $excludePeriodKey = null): int
    {
        return EtiClaim::query()
            ->forEmployee($type, $employeeId)
            ->claimed()
            // A reprocessed run must not count its own previous attempt. Without this, pressing
            // process twice would advance the employee a month each time and quietly burn the
            // twenty-four — the exact failure the ledger exists to prevent.
            ->when($excludePeriodKey, fn ($q) => $q->where('period_key', '!=', $excludePeriodKey))
            ->count();
    }

    /** How many are left. Never negative, even if history says more than twenty-four were taken. */
    public function remainingMonths(string $type, int $employeeId): int
    {
        return max(0, EtiClaim::MAX_MONTHS - $this->claimedMonths($type, $employeeId));
    }

    /**
     * The month number this period would be, if it qualifies.
     *
     * One more than whatever has already been claimed — which is the number the band is chosen by,
     * and the reason the first/second-year rate was wrong for anybody whose tenure and claims had
     * diverged.
     */
    public function nextClaimMonth(string $type, int $employeeId, ?string $excludePeriodKey = null): int
    {
        return $this->claimedMonths($type, $employeeId, $excludePeriodKey) + 1;
    }

    public function isExhausted(string $type, int $employeeId): bool
    {
        return $this->claimedMonths($type, $employeeId) >= EtiClaim::MAX_MONTHS;
    }

    /**
     * Record what a run decided for one employee in one period.
     *
     * Written for ineligible months too, because the history is only useful if it explains its gaps.
     * Keyed on (employee, period) and updated rather than inserted, so reprocessing a run corrects
     * the row instead of adding a second claim for the same month — which the unique index would
     * refuse anyway, but refusing mid-payroll is not a behaviour anybody wants.
     *
     * @param  array{bureau_company_id?:int|null, bureau_pay_run_id?:int|null, payroll_run_id?:int|null}  $links
     */
    public function record(
        string $type,
        int $employeeId,
        string $periodKey,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
        int $taxYear,
        float $amount,
        float $monthlyRemuneration,
        ?int $age,
        ?int $claimMonth,
        ?string $reason = null,
        array $links = [],
    ): EtiClaim {
        return EtiClaim::updateOrCreate(
            ['employee_type' => $type, 'employee_id' => $employeeId, 'period_key' => $periodKey],
            [
                'bureau_company_id'    => $links['bureau_company_id'] ?? null,
                'bureau_pay_run_id'    => $links['bureau_pay_run_id'] ?? null,
                'payroll_run_id'       => $links['payroll_run_id'] ?? null,
                'period_start'         => $periodStart,
                'period_end'           => $periodEnd,
                'tax_year'             => $taxYear,
                // A month that claimed nothing does not consume one of the twenty-four.
                'claim_month'          => $amount > 0 ? $claimMonth : null,
                'eligible'             => $amount > 0,
                'amount'               => $amount,
                'monthly_remuneration' => $monthlyRemuneration,
                'age'                  => $age,
                'reason'               => $reason,
                'created_by'           => Auth::id(),
            ],
        );
    }

    /**
     * The month a period belongs to.
     *
     * Taken from the period end, so a weekly run landing in the first days of a month counts towards
     * the month it ends in. Four weekly runs inside one month therefore share a period key and
     * consume a single ETI month between them, which is what SARS counts — the incentive is monthly
     * however often somebody is paid.
     */
    public function periodKeyFor(Carbon|string|null $periodEnd): string
    {
        return Carbon::parse($periodEnd ?? now())->format('Y-m');
    }

    /**
     * One employee's whole history, newest first.
     *
     * @return Collection<int, EtiClaim>
     */
    public function historyFor(string $type, int $employeeId): Collection
    {
        return EtiClaim::query()
            ->forEmployee($type, $employeeId)
            ->orderByDesc('period_key')
            ->get();
    }

    /**
     * A summary for one employee — what a profile screen and the alerts both need.
     *
     * @return array{claimed:int, remaining:int, exhausted:bool, total:float, first:?string, last:?string}
     */
    public function summaryFor(string $type, int $employeeId): array
    {
        $claims = EtiClaim::query()
            ->forEmployee($type, $employeeId)->claimed()
            ->orderBy('period_key')->get();

        return [
            'claimed'   => $claims->count(),
            'remaining' => max(0, EtiClaim::MAX_MONTHS - $claims->count()),
            'exhausted' => $claims->count() >= EtiClaim::MAX_MONTHS,
            'total'     => round((float) $claims->sum('amount'), 2),
            'first'     => $claims->first()?->period_key,
            'last'      => $claims->last()?->period_key,
        ];
    }

    /**
     * Used months for a whole set of employees at once.
     *
     * The dashboard asks this for every employee of a client, and asking per employee is how a
     * four-hundred-person register becomes four hundred queries.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, int> keyed by employee id
     */
    public function claimedMonthsFor(string $type, array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return EtiClaim::query()
            ->where('employee_type', $type)
            ->whereIn('employee_id', $employeeIds)
            ->where('eligible', true)
            ->selectRaw('employee_id, count(*) as claimed')
            ->groupBy('employee_id')
            ->pluck('claimed', 'employee_id');
    }
}
