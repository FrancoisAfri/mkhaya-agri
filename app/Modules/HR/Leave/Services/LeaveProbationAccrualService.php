<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveType;
use Carbon\Carbon;

class LeaveProbationAccrualService
{
    // BCEA §20(2)(b): 1 day accrued per 26 days worked during probation
    private const WORKING_DAYS_PER_ACCRUAL = 26;

    public function __construct(
        private readonly LeaveBalanceRepositoryInterface $repo,
        private readonly WorkingDaysCalculator           $calculator,
    ) {}

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Total hours the employee should have accrued from employment start to $asOf.
     * Capped at the leave type's bcea_minimum_days (default 15 days = 120 h).
     */
    public function calculateAccruedHours(
        HRPerson  $employee,
        LeaveType $leaveType,
        ?Carbon   $asOf = null,
    ): float {
        $start = $this->employmentStart($employee);
        if (! $start) {
            return 0.0;
        }

        $asOf ??= now();
        if ($asOf->lessThanOrEqualTo($start)) {
            return 0.0;
        }

        $workingDays = (float) $this->calculator->calculate(
            $start->format('Y-m-d'),
            $asOf->format('Y-m-d'),
        );

        $capHours = (float) ($leaveType->bcea_minimum_days ?? 15) * 8;
        $earned   = floor($workingDays / self::WORKING_DAYS_PER_ACCRUAL) * 8;

        return min((float) $earned, $capHours);
    }

    /**
     * Whether the employee has served the minimum probation period for this leave type.
     */
    public function isProbationComplete(HRPerson $employee, LeaveType $leaveType): bool
    {
        return $this->probationDaysRemaining($employee, $leaveType) === 0;
    }

    /**
     * Calendar days still to serve before probation unlocks.
     */
    public function probationDaysRemaining(HRPerson $employee, LeaveType $leaveType): int
    {
        $minDays = (int) ($leaveType->require_probation_period_days ?? 182);
        $start   = $this->employmentStart($employee);
        if (! $start) {
            return $minDays;
        }

        return max(0, $minDays - (int) $start->diffInDays(now()));
    }

    // ── Mutations ─────────────────────────────────────────────────────────────

    /**
     * Credit any accrual the employee has earned but not yet received.
     *
     * Safe to call multiple times — only credits the net gap between what
     * has already been allocated and what should have accrued.
     *
     * Used for:
     *  - Normal monthly accrual cron
     *  - Late account creation: pass $from = actual hire date to backfill
     *    all missed periods in one transaction.
     *
     * @param  Carbon|null  $from   Override the accrual start date (e.g. hire date when
     *                              the account was created late). Defaults to date_joined.
     * @param  Carbon|null  $asOf   Calculate up to this date. Defaults to now().
     * @param  string|null  $note   Audit note appended to the transaction description.
     * @return float                Hours credited (0 if nothing was owed).
     */
    public function applyAccrual(
        HRPerson  $employee,
        LeaveType $leaveType,
        ?Carbon   $from = null,
        ?Carbon   $asOf = null,
        ?string   $note = null,
    ): float {
        $start = $from ?? $this->employmentStart($employee);
        if (! $start) {
            return 0.0;
        }

        $asOf  ??= now();
        $cycle   = LeaveCycle::active();

        $targetHours = $this->calculateAccruedHours($employee, $leaveType, $asOf);

        // Sum every credit that was an allocation or opening-balance entry
        $alreadyCredited = (float) LeaveBalanceTransaction::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->whereIn('transaction_type', [
                LeaveTransactionType::Allocation->value,
                LeaveTransactionType::OpeningBalance->value,
            ])
            ->sum('hours');

        $delta = $targetHours - $alreadyCredited;
        if ($delta <= 0.0) {
            return 0.0;
        }

        $backdated  = $from !== null;
        $description = $backdated
            ? sprintf(
                'Backdated probation accrual: %.1f h credited (%.1f h earned from %s − %.1f h already allocated).%s',
                $delta,
                $targetHours,
                $start->format('d M Y'),
                $alreadyCredited,
                $note ? " Note: {$note}" : '',
            )
            : sprintf(
                'Probation accrual: %.1f h credited (%.1f h earned − %.1f h already allocated).%s',
                $delta,
                $targetHours,
                $alreadyCredited,
                $note ? " Note: {$note}" : '',
            );

        $this->repo->credit(
            hrPersonId:  $employee->id,
            leaveTypeId: $leaveType->id,
            hours:       $delta,
            type:        LeaveTransactionType::Allocation->value,
            meta: [
                'leave_cycle_id' => $cycle?->id,
                'description'    => $description,
                'performed_by'   => null,
            ],
        );

        return $delta;
    }

    /**
     * Reconcile current balance against expected accrual and apply a Correction
     * transaction for the net difference (positive or negative).
     *
     * Call this after a manual date_joined correction or after a bulk import.
     * Returns the delta applied (positive = credited, negative = debited).
     */
    public function reconcile(HRPerson $employee, LeaveType $leaveType): float
    {
        $expected = $this->calculateAccruedHours($employee, $leaveType);
        $actual   = $this->repo->currentBalance($employee->id, $leaveType->id);
        $delta    = round($expected - $actual, 4);

        if (abs($delta) < 0.01) {
            return 0.0;
        }

        $cycle = LeaveCycle::active();
        $desc  = sprintf(
            'Accrual reconciliation: %s %.2f h (expected %.2f h, current balance %.2f h).',
            $delta > 0 ? 'credited' : 'debited',
            abs($delta),
            $expected,
            $actual,
        );

        if ($delta > 0) {
            $this->repo->credit(
                hrPersonId:  $employee->id,
                leaveTypeId: $leaveType->id,
                hours:       $delta,
                type:        LeaveTransactionType::Correction->value,
                meta:        ['leave_cycle_id' => $cycle?->id, 'description' => $desc],
            );
        } else {
            $this->repo->debit(
                hrPersonId:  $employee->id,
                leaveTypeId: $leaveType->id,
                hours:       abs($delta),
                type:        LeaveTransactionType::Correction->value,
                meta:        ['leave_cycle_id' => $cycle?->id, 'description' => $desc],
            );
        }

        return $delta;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function employmentStart(HRPerson $employee): ?Carbon
    {
        if (! $employee->date_joined) {
            return null;
        }

        return is_numeric($employee->date_joined)
            ? Carbon::createFromTimestamp((int) $employee->date_joined)
            : Carbon::parse($employee->date_joined);
    }
}
