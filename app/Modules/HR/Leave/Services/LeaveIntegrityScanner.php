<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Account-setup & ledger-integrity scanner. Catches the accounts whose leave silently
 * never runs — no leave profile, missing accrual, missing birthdate/join date, no sick
 * setup, negative balances, and approved overtime that never credited. Runs inside the
 * current tenant context (RLS scopes hr_people / leave tables); writes to leave_anomaly_flags.
 */
class LeaveIntegrityScanner
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly WorkingDaysCalculator $workingDays,
    ) {}

    /** Scan every active employee in the current company. Returns the number of new flags raised. */
    public function scanCurrentCompany(): int
    {
        $cycleId  = LeaveCycle::active()?->id;
        $sickType = LeaveType::where('status', 1)->where('statutory_type', 'sick')->first();
        $created  = 0;

        HRPerson::where('status', 1)->get()->each(function (HRPerson $emp) use (&$created, $cycleId, $sickType) {
            $created += $this->scanEmployee($emp, $cycleId, $sickType);
        });

        return $created;
    }

    /** Returns the number of NEW flags raised for this employee. */
    private function scanEmployee(HRPerson $emp, ?int $cycleId, ?LeaveType $sickType): int
    {
        $n = 0;
        $profile = (int) ($emp->getRawOriginal('leave_profile') ?? 0);
        $dob     = $emp->getRawOriginal('date_of_birth');
        $joined  = $emp->getRawOriginal('date_joined');

        // 1. No leave profile → nothing will ever accrue for this employee.
        if ($profile <= 0) {
            $n += (int) $this->flag($emp, LeaveAnomalyType::NoLeaveProfile,
                'Employee has no leave profile assigned, so no leave will accrue.',
                ['leave_profile' => $profile]);
        }

        // 2. Missing date of birth → no birthday half-day.
        if (empty($dob)) {
            $n += (int) $this->flag($emp, LeaveAnomalyType::MissingBirthdate,
                'No date of birth on file — the employee will not receive birthday leave.',
                ['date_of_birth' => $dob]);
        }

        // 3. Missing join date → no sick-leave cycle / no prorated accrual.
        if (empty($joined)) {
            $n += (int) $this->flag($emp, LeaveAnomalyType::MissingJoinDate,
                'No join date on file — sick-leave eligibility and accrual cannot be calculated.',
                ['date_joined' => $joined]);
        }

        if ($profile > 0) {
            $allocations = LeaveProfileAllocation::where('leave_profile_id', $profile)
                ->where('active', true)->get();

            // 4. Has allocations but no ledger movement at all → accrual never ran.
            if ($allocations->isNotEmpty()) {
                $hasLedger = LeaveBalanceTransaction::where('employee_id', $emp->id)->exists();
                if (! $hasLedger) {
                    $n += (int) $this->flag($emp, LeaveAnomalyType::MissingAccrual,
                        'Employee has leave allocations but no leave transactions — accrual has never run.',
                        ['allocation_types' => $allocations->pluck('leave_type_id')->all()]);
                }

                // 5. Negative balance on any allocated type.
                foreach ($allocations as $alloc) {
                    $bal = $this->balances->getBalance($emp->id, $alloc->leave_type_id, $cycleId);
                    if ($bal < 0) {
                        $n += (int) $this->flag($emp, LeaveAnomalyType::NegativeBalance,
                            'Negative leave balance detected on an allocated leave type.',
                            ['leave_type_id' => $alloc->leave_type_id, 'balance_hours' => $bal]);
                    }
                }
            }

            // 6. Sick leave exists company-wide but this employee has no sick allocation.
            if ($sickType) {
                $hasSick = $allocations->firstWhere('leave_type_id', $sickType->id);
                if (! $hasSick) {
                    $n += (int) $this->flag($emp, LeaveAnomalyType::NoSickLeaveSetup,
                        'Employee has no sick-leave allocation, so statutory sick leave will not be granted.',
                        ['sick_leave_type_id' => $sickType->id]);
                }
            }
        }

        // 7. Approved overtime that never credited annual leave (integrity of the OT-credit flow).
        $uncredited = LeaveRequest::where('employee_id', $emp->id)
            ->where('is_overtime', true)
            ->where('status', 'approved')
            ->whereDoesntHave('balanceTransactions', fn ($q) => $q->where('transaction_type', LeaveTransactionType::OvertimeCredit->value))
            ->pluck('request_number');
        if ($uncredited->isNotEmpty()) {
            $n += (int) $this->flag($emp, LeaveAnomalyType::OvertimeNotCredited,
                'Approved overtime was not credited to annual leave.',
                ['requests' => $uncredited->all()]);
        }

        // 8. Weekly cross-checks (last-week scoped): attendance no-show, cron-didn't-run, recent overdraw.
        $n += $this->checkNoShow($emp);
        $n += $this->checkStaleAccrual($emp, $profile);
        $n += $this->checkSickCycleOverdue($emp, $profile, $sickType);
        $n += $this->checkRecentNegativeTxn($emp);

        return $n;
    }

    /**
     * Attendance safety-net: working days in the last 7 where the employee neither clocked in
     * (ERS) nor had approved leave — the absence cron should have caught these, but this picks
     * up anything it missed.
     */
    private function checkNoShow(HRPerson $emp): int
    {
        $missed = [];
        for ($i = 1; $i <= 7; $i++) {
            $day = Carbon::today()->subDays($i);
            if (! $this->workingDays->isWorkingDay($day)) {
                continue;
            }
            $d = $day->toDateString();

            $clockedIn = AttendanceRecord::where('employee_id', $emp->id)
                ->whereDate('date', $d)->whereNotNull('clock_in')->exists();
            if ($clockedIn) {
                continue;
            }

            $onLeave = LeaveRequest::where('employee_id', $emp->id)
                ->where('status', LeaveRequestStatus::Approved->value)
                ->whereDate('start_date', '<=', $d)->whereDate('end_date', '>=', $d)->exists();
            if (! $onLeave) {
                $missed[] = $d;
            }
        }

        if (! $missed) {
            return 0;
        }

        return (int) $this->flag($emp, LeaveAnomalyType::UnauthorisedAbsence,
            'Did not clock in and had no approved leave on ' . count($missed) . ' working day(s) in the last week.',
            ['dates' => $missed]);
    }

    /**
     * Cron-health: a monthly-accrual allocation exists but no allocation was posted this month —
     * the accrual cron did not run (or failed) for this employee.
     */
    private function checkStaleAccrual(HRPerson $emp, int $profile): int
    {
        if ($profile <= 0 || ! LeaveCycle::active()) {
            return 0;
        }

        $hasMonthlyAccrual = LeaveProfileAllocation::where('leave_profile_id', $profile)
            ->where('active', true)->whereNotNull('accrual_rate_hours_per_month')->exists();
        if (! $hasMonthlyAccrual) {
            return 0;
        }

        // Only meaningful once the employee has been employed for a full month.
        $joined = $emp->getRawOriginal('date_joined');
        if ($joined && Carbon::createFromTimestamp((int) $joined)->greaterThan(Carbon::now()->startOfMonth())) {
            return 0;
        }

        $postedThisMonth = LeaveBalanceTransaction::where('employee_id', $emp->id)
            ->where('transaction_type', LeaveTransactionType::Allocation->value)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->exists();
        if ($postedThisMonth) {
            return 0;
        }

        return (int) $this->flag($emp, LeaveAnomalyType::StaleAccrual,
            'Monthly accrual has not run this month — expected leave was not added.',
            ['month' => Carbon::now()->format('Y-m')]);
    }

    /**
     * Cron-health: employee is set up for sick leave but has no current 36-month sick cycle —
     * the sick-leave cycle cron did not grant/renew the entitlement.
     */
    private function checkSickCycleOverdue(HRPerson $emp, int $profile, ?LeaveType $sickType): int
    {
        if ($profile <= 0 || ! $sickType) {
            return 0;
        }

        $hasSickAlloc = LeaveProfileAllocation::where('leave_profile_id', $profile)
            ->where('leave_type_id', $sickType->id)->where('active', true)->exists();
        if (! $hasSickAlloc) {
            return 0; // NoSickLeaveSetup covers this case
        }

        $openCycle = DB::table('employee_sick_leave_cycles')
            ->where('employee_id', $emp->id)
            ->whereDate('cycle_end', '>=', Carbon::today())
            ->exists();
        if ($openCycle) {
            return 0;
        }

        return (int) $this->flag($emp, LeaveAnomalyType::SickCycleOverdue,
            'No current sick-leave cycle — the sick-leave cycle cron has not granted the entitlement.',
            ['sick_leave_type_id' => $sickType->id]);
    }

    /**
     * Last-week ledger check: a transaction in the last 7 days drove the balance negative
     * (an overdraw the balance-check might otherwise show only as a static negative).
     */
    private function checkRecentNegativeTxn(HRPerson $emp): int
    {
        $overdraw = LeaveBalanceTransaction::where('employee_id', $emp->id)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('balance_after', '<', 0)
            ->orderByDesc('id')
            ->first();
        if (! $overdraw) {
            return 0;
        }

        return (int) $this->flag($emp, LeaveAnomalyType::RecentNegativeTxn,
            'A leave transaction in the last week left the balance negative.',
            [
                'leave_type_id' => $overdraw->leave_type_id,
                'transaction'   => $overdraw->transaction_type instanceof LeaveTransactionType ? $overdraw->transaction_type->value : (string) $overdraw->transaction_type,
                'balance_after' => (float) $overdraw->balance_after,
                'date'          => optional($overdraw->created_at)->toDateString(),
            ]);
    }

    /** Idempotent flag writer — skips if an OPEN flag of the same type already exists for the employee. */
    private function flag(HRPerson $emp, LeaveAnomalyType $type, string $description, array $evidence): bool
    {
        $exists = LeaveAnomalyFlag::where('employee_id', $emp->id)
            ->where('anomaly_type', $type->value)
            ->where('status', 'open')
            ->exists();
        if ($exists) {
            return false;
        }

        LeaveAnomalyFlag::create([
            'employee_id'      => $emp->id,
            'anomaly_type'     => $type->value,
            'severity'         => $type->severity(),
            'detection_method' => 'rule_based',
            'description'      => $description,
            'evidence'         => $evidence,
            'status'           => 'open',
            'detected_at'      => now(),
        ]);

        return true;
    }
}
