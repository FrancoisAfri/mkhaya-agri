<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Contracts\LeaveBalanceRepositoryInterface;
use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Carbon\Carbon;

class BceaLeaveValidator
{
    public function __construct(
        private readonly LeaveRequestRepositoryInterface $requestRepo,
        private readonly LeaveBalanceRepositoryInterface $balanceRepo,
        private readonly WorkingDaysCalculator           $calculator,
    ) {}

    public function validate(HRPerson $employee, LeaveType $leaveType, SubmitLeaveRequestDTO $dto): array
    {
        $errors   = [];
        $warnings = [];

        // 1. Minimum service period (annual leave: 26 weeks BCEA)
        if ($leaveType->statutory_type === 'annual' && $leaveType->require_probation_period_days) {
            $daysWorked = $this->daysWorked($employee);
            if ($daysWorked < ($leaveType->require_probation_period_days ?? 182)) {
                $errors[] = 'BCEA §20: Employee has not completed the minimum service period for annual leave. A minimum of 26 weeks (182 days) is required.';
            }
        }

        // 2. Balance validation
        $balance = $this->balanceRepo->currentBalance($employee->id, $leaveType->id);
        $config  = LeaveConfiguration::first();
        $workingHours = $this->calculator->calculate($dto->startDate, $dto->endDate) * 8;

        if ($balance < $workingHours) {
            $allowNegative = $this->allowNegative($leaveType, $config);
            if (! $allowNegative) {
                $errors[] = "Insufficient leave balance. Available: " . round($balance / 8, 1) . " days, Requested: " . round($workingHours / 8, 1) . " days.";
            } else {
                $maxNeg = $this->negativeMax($leaveType, $config);
                if (($balance - $workingHours) < -$maxNeg) {
                    $errors[] = "Requested leave would exceed the maximum allowed negative balance of {$maxNeg} hours.";
                } else {
                    $warnings[] = 'This request will place your leave balance in negative. Please confirm.';
                }
            }
        }

        // 3. Overlap detection
        $overlapping = $this->requestRepo->overlapping(
            $employee->id,
            $dto->startDate,
            $dto->endDate
        );
        if ($overlapping->isNotEmpty()) {
            $errors[] = 'BCEA §20: A leave request already exists for the overlapping period (' . $overlapping->first()->request_number . ').';
        }

        // 4. Maximum consecutive days
        if ($leaveType->max_consecutive_hours) {
            $maxDays = $leaveType->max_consecutive_hours / 8;
            if ($workingHours / 8 > $maxDays) {
                $warnings[] = "Leave type \"{$leaveType->name}\" has a maximum of {$maxDays} consecutive days. Please split into separate requests.";
            }
        }

        // 5. Notice period (future-date only — cannot apply for past leave retroactively for statutory types)
        if (in_array($leaveType->statutory_type, ['annual', 'study']) && Carbon::parse($dto->startDate)->isPast()) {
            $warnings[] = 'Backdated leave applications require HR manager override.';
        }

        // 6. FRL entitlement cap (BCEA §27: 3 days per leave cycle)
        if ($leaveType->statutory_type === 'family_responsibility') {
            $frl = $this->checkFrlEntitlement($employee, $leaveType, $dto);
            $errors   = array_merge($errors, $frl['errors']);
            $warnings = array_merge($warnings, $frl['warnings']);
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    private function checkFrlEntitlement(HRPerson $employee, LeaveType $leaveType, SubmitLeaveRequestDTO $dto): array
    {
        $capDays       = (float) ($leaveType->bcea_minimum_days ?? 3);
        $requestedDays = (float) $this->calculator->calculate($dto->startDate, $dto->endDate);
        $cycle         = LeaveCycle::active();

        $query = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->whereNotIn('status', ['rejected', 'cancelled']);

        if ($cycle) {
            $query->where('start_date', '>=', $cycle->start_date)
                  ->where('start_date', '<=', $cycle->end_date);
        } else {
            $query->whereYear('start_date', now()->year);
        }

        $usedDays = (float) $query->sum('working_days');

        if ($usedDays >= $capDays) {
            return [
                'errors'   => ["BCEA §27: Your Family Responsibility Leave entitlement of {$capDays} day(s) per cycle has been fully used ({$usedDays} day(s) taken)."],
                'warnings' => [],
            ];
        }

        if (($usedDays + $requestedDays) > $capDays) {
            $remaining = $capDays - $usedDays;
            return [
                'errors'   => ["BCEA §27: Only {$remaining} day(s) of Family Responsibility Leave remain this cycle. You used {$usedDays} of {$capDays} day(s) and are requesting {$requestedDays} day(s)."],
                'warnings' => [],
            ];
        }

        if ($usedDays > 0) {
            $remaining = $capDays - $usedDays;
            return [
                'errors'   => [],
                'warnings' => ["FRL: You have {$remaining} day(s) of Family Responsibility Leave remaining this cycle."],
            ];
        }

        return ['errors' => [], 'warnings' => []];
    }

    private function daysWorked(HRPerson $employee): int
    {
        if (! $employee->date_joined) {
            return 0;
        }

        $start = is_numeric($employee->date_joined)
            ? Carbon::createFromTimestamp((int) $employee->date_joined)
            : Carbon::parse($employee->date_joined);

        return (int) $start->diffInDays(now());
    }

    private function allowNegative(LeaveType $lt, ?object $config): bool
    {
        return match($lt->statutory_type) {
            'annual' => (bool) ($config?->allow_annual_leave_credit ?? false),
            'sick'   => (bool) ($config?->allow_sick_leave_credit   ?? false),
            default  => false,
        };
    }

    private function negativeMax(LeaveType $lt, ?object $config): float
    {
        return match($lt->statutory_type) {
            'annual' => (float) ($config?->allow_annual_negative_days ?? 0) * 8,
            'sick'   => (float) ($config?->allow_sick_negative_days   ?? 0) * 8,
            default  => 0,
        };
    }
}
