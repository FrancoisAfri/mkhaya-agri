<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveBalanceRepositoryInterface $repo,
    ) {}

    public function getBalance(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): float
    {
        return $this->repo->currentBalance($hrPersonId, $leaveTypeId, $cycleId);
    }

    public function getAllBalances(int $hrPersonId): Collection
    {
        $cycle = LeaveCycle::active();
        return $this->repo->allBalances($hrPersonId, $cycle?->id);
    }

    /**
     * Returns future committed hours per leave type for an employee.
     * "Future" = active leave requests (not yet started) that have already
     * reduced the ledger balance via reservation/consumption transactions.
     * Adding these back gives the Actual Balance (balance before upcoming leaves).
     *
     * @return array<int, float>  leave_type_id => hours
     */
    public function getFutureCommittedHours(int $hrPersonId): array
    {
        $rows = DB::table('leave_requests')
            ->where('employee_id', $hrPersonId)
            ->whereIn('status', [
                'approved', 'pending', 'pending_manager',
                'pending_department', 'pending_hr', 'pending_payroll', 'escalated',
            ])
            ->where('start_date', '>', now()->toDateString())
            ->select('leave_type_id', DB::raw('SUM(working_hours) as future_hours'))
            ->groupBy('leave_type_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->leave_type_id] = (float) $row->future_hours;
        }
        return $result;
    }

    public function reserve(LeaveRequest $request): void
    {
        $this->repo->debit(
            hrPersonId:   $request->employee_id,
            leaveTypeId:  $request->leave_type_id,
            hours:        (float) $request->working_hours,
            type:         LeaveTransactionType::RequestReservation->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id,
                'reference_type' => 'leave_request',
                'reference_id'   => $request->id,
                'description'    => "Balance reserved for {$request->request_number}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    public function release(LeaveRequest $request): void
    {
        $this->repo->credit(
            hrPersonId:   $request->employee_id,
            leaveTypeId:  $request->leave_type_id,
            hours:        (float) $request->hours_reserved,
            type:         LeaveTransactionType::RequestRelease->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id,
                'reference_type' => 'leave_request',
                'reference_id'   => $request->id,
                'description'    => "Balance released for cancelled/rejected {$request->request_number}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    public function releaseApproved(LeaveRequest $request): void
    {
        // An approved request carries a single net deduction (its reservation was
        // converted to a consumption on approval), so one credit fully restores it.
        $this->repo->credit(
            hrPersonId:  $request->employee_id,
            leaveTypeId: $request->leave_type_id,
            hours:       (float) $request->working_hours,
            type:        LeaveTransactionType::RequestRelease->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id,
                'reference_type' => 'leave_request',
                'reference_id'   => $request->id,
                'description'    => "Admin cancellation — reversal of {$request->request_number}",
                'performed_by'   => Auth::id(),
            ],
        );
    }

    /**
     * Confirm an approved request. The balance was ALREADY reduced when the
     * request was reserved at application time, so approval must NOT deduct a
     * second time. We convert the existing reservation into a consumption in
     * place (same hours, same balance) — a single, correct deduction.
     */
    public function consume(LeaveRequest $request): void
    {
        $reservation = LeaveBalanceTransaction::where('reference_type', 'leave_request')
            ->where('reference_id', $request->id)
            ->where('transaction_type', LeaveTransactionType::RequestReservation->value)
            ->latest('id')
            ->first();

        if ($reservation) {
            $reservation->update([
                'transaction_type' => LeaveTransactionType::RequestConsumption->value,
                'description'       => "Leave consumed for approved {$request->request_number}",
                'performed_by'      => Auth::id(),
            ]);

            return;
        }

        // No reservation on record (e.g. legacy/imported request) — post the
        // consumption as the first and only deduction.
        $this->repo->debit(
            hrPersonId:   $request->employee_id,
            leaveTypeId:  $request->leave_type_id,
            hours:        (float) $request->working_hours,
            type:         LeaveTransactionType::RequestConsumption->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id,
                'reference_type' => 'leave_request',
                'reference_id'   => $request->id,
                'description'    => "Leave consumed for approved {$request->request_number}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    /**
     * Approved overtime earns time off: credit the employee's ANNUAL leave by the
     * approved days (using the request's computed working_hours, i.e. days at the
     * company's standard day length). Idempotent — never credits the same request twice.
     */
    public function overtimeCredit(LeaveRequest $request): void
    {
        $already = LeaveBalanceTransaction::where('reference_type', 'leave_request')
            ->where('reference_id', $request->id)
            ->where('transaction_type', LeaveTransactionType::OvertimeCredit->value)
            ->exists();
        if ($already) {
            return;
        }

        $annualType = LeaveType::where('status', 1)->where('statutory_type', 'annual')->first();
        if (! $annualType) {
            return; // no annual leave type configured — nothing to credit
        }

        $hours = (float) $request->working_hours;
        if ($hours <= 0) {
            return;
        }

        $days = rtrim(rtrim(number_format((float) $request->working_days, 2, '.', ''), '0'), '.');

        $this->repo->credit(
            hrPersonId:  $request->employee_id,
            leaveTypeId: $annualType->id,
            hours:       $hours,
            type:        LeaveTransactionType::OvertimeCredit->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id ?: LeaveCycle::active()?->id,
                'reference_type' => 'leave_request',
                'reference_id'   => $request->id,
                'description'    => "Overtime credit ({$days} day(s)) from {$request->request_number}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    /**
     * Reverse a previously-granted overtime credit (e.g. the overtime request is
     * cancelled after approval): debit annual leave by the credited hours. Idempotent.
     */
    public function overtimeReverse(LeaveRequest $request): void
    {
        $creditExists = LeaveBalanceTransaction::where('reference_type', 'leave_request')
            ->where('reference_id', $request->id)
            ->where('transaction_type', LeaveTransactionType::OvertimeCredit->value)
            ->exists();
        if (! $creditExists) {
            return;
        }

        $alreadyReversed = LeaveBalanceTransaction::where('reference_type', 'overtime_reversal')
            ->where('reference_id', $request->id)
            ->exists();
        if ($alreadyReversed) {
            return;
        }

        $annualType = LeaveType::where('status', 1)->where('statutory_type', 'annual')->first();
        if (! $annualType) {
            return;
        }

        $this->repo->debit(
            hrPersonId:  $request->employee_id,
            leaveTypeId: $annualType->id,
            hours:       (float) $request->working_hours,
            type:        LeaveTransactionType::Correction->value,
            meta: [
                'leave_cycle_id' => $request->leave_cycle_id ?: LeaveCycle::active()?->id,
                'reference_type' => 'overtime_reversal',
                'reference_id'   => $request->id,
                'description'    => "Reversal of overtime credit for cancelled {$request->request_number}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    /**
     * Post a ledger Correction (positive = credit, negative = debit) tagged to a source
     * reference. Used by the anomaly remediation flow (fix + reverse) so corrections and
     * their reversals are linked and auditable on the append-only ledger.
     */
    public function correct(int $hrPersonId, int $leaveTypeId, float $hours, string $referenceType, int $referenceId, string $description): void
    {
        if ($hours == 0.0) {
            return;
        }

        $meta = [
            'leave_cycle_id' => LeaveCycle::active()?->id,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
            'description'    => $description,
            'performed_by'   => Auth::id(),
        ];

        if ($hours > 0) {
            $this->repo->credit($hrPersonId, $leaveTypeId, $hours, LeaveTransactionType::Correction->value, $meta);
        } else {
            $this->repo->debit($hrPersonId, $leaveTypeId, abs($hours), LeaveTransactionType::Correction->value, $meta);
        }
    }

    public function manualAdjust(int $hrPersonId, int $leaveTypeId, float $hours, string $reason): void
    {
        $cycle = LeaveCycle::active();
        $meta  = [
            'leave_cycle_id' => $cycle?->id,
            'description'    => "Manual adjustment: {$reason}",
            'performed_by'   => Auth::id(),
        ];

        if ($hours >= 0) {
            $this->repo->credit(
                hrPersonId:  $hrPersonId,
                leaveTypeId: $leaveTypeId,
                hours:       $hours,
                type:        LeaveTransactionType::ManualAdjustment->value,
                meta:        $meta,
            );
        } else {
            $this->repo->debit(
                hrPersonId:  $hrPersonId,
                leaveTypeId: $leaveTypeId,
                hours:       abs($hours),
                type:        LeaveTransactionType::ManualAdjustment->value,
                meta:        $meta,
            );
        }
    }

    public function payout(int $hrPersonId, int $leaveTypeId, float $hours, int $payoutRecordId, string $reference): void
    {
        $cycle = LeaveCycle::active();
        $this->repo->debit(
            hrPersonId:  $hrPersonId,
            leaveTypeId: $leaveTypeId,
            hours:       $hours,
            type:        LeaveTransactionType::Payout->value,
            meta:        [
                'leave_cycle_id' => $cycle?->id,
                'description'    => "Leave payout #{$payoutRecordId}" . ($reference ? ": {$reference}" : ''),
                'performed_by'   => Auth::id(),
            ]
        );
    }

    public function getTransactionHistory(int $hrPersonId, int $leaveTypeId): Collection
    {
        $cycle = LeaveCycle::active();
        return $this->repo->transactions($hrPersonId, $leaveTypeId, $cycle?->id);
    }

    public function allocateYearly(int $hrPersonId, int $leaveTypeId, float $hours, int $cycleId, string $cycleName): void
    {
        $this->repo->credit(
            hrPersonId:   $hrPersonId,
            leaveTypeId:  $leaveTypeId,
            hours:        $hours,
            type:         LeaveTransactionType::Allocation->value,
            meta: [
                'leave_cycle_id' => $cycleId,
                'description'    => "Annual allocation — {$cycleName}",
                'performed_by'   => null,
            ]
        );
    }

    public function allocateProrated(HRPerson $employee, LeaveProfileAllocation $allocation, LeaveCycle $cycle): void
    {
        $dateJoined = $employee->date_joined;
        if (! $dateJoined) {
            return;
        }

        $joinDate   = is_numeric($dateJoined) ? Carbon::createFromTimestamp($dateJoined) : Carbon::parse($dateJoined);
        $cycleStart = $cycle->start_date instanceof Carbon ? $cycle->start_date : Carbon::parse($cycle->start_date);
        $cycleEnd   = $cycle->end_date instanceof Carbon   ? $cycle->end_date   : Carbon::parse($cycle->end_date);

        if ($joinDate->lte($cycleStart)) {
            return;
        }

        if ($joinDate->gte($cycleEnd)) {
            return;
        }

        $totalDays     = $cycleStart->diffInDays($cycleEnd) ?: 1;
        $remainingDays = $joinDate->diffInDays($cycleEnd);
        $ratio         = $remainingDays / $totalDays;
        $proratedHours = round((float) $allocation->default_hours * $ratio, 2);

        if ($proratedHours <= 0) {
            return;
        }

        $this->repo->credit(
            hrPersonId:   $employee->id,
            leaveTypeId:  $allocation->leave_type_id,
            hours:        $proratedHours,
            type:         LeaveTransactionType::Allocation->value,
            meta: [
                'leave_cycle_id' => $cycle->id,
                'description'    => "Prorated allocation ({$remainingDays}/{$totalDays} days) — {$cycle->name}",
                'performed_by'   => null,
            ]
        );
    }

    public function resetBalance(int $hrPersonId, int $leaveTypeId, string $reason): void
    {
        $cycle   = LeaveCycle::active();
        $current = $this->repo->currentBalance($hrPersonId, $leaveTypeId, $cycle?->id);

        if ($current == 0.0) {
            return;
        }

        $this->repo->credit(
            hrPersonId:   $hrPersonId,
            leaveTypeId:  $leaveTypeId,
            hours:        -$current,
            type:         LeaveTransactionType::Correction->value,
            meta: [
                'leave_cycle_id' => $cycle?->id,
                'description'    => "Balance reset to zero: {$reason}",
                'performed_by'   => Auth::id(),
            ]
        );
    }

    public function applyForfeiture(int $hrPersonId, int $leaveTypeId, float $hours, int $cycleId, string $cycleName): void
    {
        $this->repo->debit(
            hrPersonId:   $hrPersonId,
            leaveTypeId:  $leaveTypeId,
            hours:        $hours,
            type:         LeaveTransactionType::Forfeiture->value,
            meta: [
                'leave_cycle_id' => $cycleId,
                'description'    => "Forfeiture at cycle close — {$cycleName}",
                'performed_by'   => null,
            ]
        );
    }

    public function getAllocationBreakdown(int $hrPersonId, int $leaveTypeId, ?int $cycleId = null): array
    {
        $txns = $this->repo->transactions($hrPersonId, $leaveTypeId, $cycleId);

        $breakdown = [
            'opening'   => 0.0,
            'allocated' => 0.0,
            'carryover' => 0.0,
            'consumed'  => 0.0,
            'reserved'  => 0.0,
            'payout'    => 0.0,
            'forfeiture'=> 0.0,
            'adjusted'  => 0.0,
        ];

        foreach ($txns as $t) {
            $type  = $t->transaction_type instanceof LeaveTransactionType ? $t->transaction_type : LeaveTransactionType::tryFrom((string) $t->transaction_type);
            $hours = abs((float) $t->hours);

            match ($type) {
                LeaveTransactionType::OpeningBalance     => $breakdown['opening']    += $hours,
                LeaveTransactionType::Allocation         => $breakdown['allocated']  += $hours,
                LeaveTransactionType::Carryover          => $breakdown['carryover']  += $hours,
                LeaveTransactionType::RequestConsumption => $breakdown['consumed']   += $hours,
                LeaveTransactionType::RequestReservation => $breakdown['reserved']   += $hours,
                LeaveTransactionType::Payout             => $breakdown['payout']     += $hours,
                LeaveTransactionType::Forfeiture         => $breakdown['forfeiture'] += $hours,
                LeaveTransactionType::ManualAdjustment,
                LeaveTransactionType::Correction,
                LeaveTransactionType::RequestRelease     => $breakdown['adjusted']   += (float) $t->hours,
                default                                  => null,
            };
        }

        return $breakdown;
    }
}
