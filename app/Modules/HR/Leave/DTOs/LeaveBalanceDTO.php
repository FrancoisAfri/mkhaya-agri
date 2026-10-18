<?php

namespace App\Modules\HR\Leave\DTOs;

readonly class LeaveBalanceDTO
{
    public function __construct(
        public int    $employeeId,
        public int    $leaveTypeId,
        public string $leaveTypeName,
        public float  $balanceHours,
        public float  $balanceDays,
        public float  $usedHours,
        public float  $pendingHours,
        public float  $availableHours,
        public bool   $allowNegative,
        public float  $negativeMaxHours,
    ) {}

    public function toArray(): array
    {
        return [
            'employee_id'       => $this->employeeId,
            'leave_type_id'     => $this->leaveTypeId,
            'leave_type_name'   => $this->leaveTypeName,
            'balance_hours'     => round($this->balanceHours, 2),
            'balance_days'      => round($this->balanceDays, 2),
            'used_hours'        => round($this->usedHours, 2),
            'pending_hours'     => round($this->pendingHours, 2),
            'available_hours'   => round($this->availableHours, 2),
            'allow_negative'    => $this->allowNegative,
            'negative_max_hours' => $this->negativeMaxHours,
        ];
    }
}
