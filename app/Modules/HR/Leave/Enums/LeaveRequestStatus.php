<?php

namespace App\Modules\HR\Leave\Enums;

enum LeaveRequestStatus: string
{
    case Draft            = 'draft';
    case Pending          = 'pending';
    case PendingManager   = 'pending_manager';
    case PendingDepartment = 'pending_department';
    case PendingHr        = 'pending_hr';
    case PendingPayroll   = 'pending_payroll';
    case Approved         = 'approved';
    case Rejected         = 'rejected';
    case Cancelled        = 'cancelled';
    case Escalated        = 'escalated';

    public function label(): string
    {
        return match($this) {
            self::Draft             => 'Draft',
            self::Pending           => 'Pending',
            self::PendingManager    => 'Awaiting Manager',
            self::PendingDepartment => 'Awaiting Department Head',
            self::PendingHr         => 'Awaiting HR',
            self::PendingPayroll    => 'Awaiting Payroll',
            self::Approved          => 'Approved',
            self::Rejected          => 'Rejected',
            self::Cancelled         => 'Cancelled',
            self::Escalated         => 'Escalated',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft             => 'secondary',
            self::Pending,
            self::PendingManager,
            self::PendingDepartment,
            self::PendingHr,
            self::PendingPayroll    => 'warning',
            self::Approved          => 'success',
            self::Rejected          => 'error',
            self::Cancelled         => 'secondary',
            self::Escalated         => 'orange',
        };
    }

    public function isPending(): bool
    {
        return in_array($this, [
            self::Pending,
            self::PendingManager,
            self::PendingDepartment,
            self::PendingHr,
            self::PendingPayroll,
            self::Escalated,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled]);
    }

    public function nextApprovalStatus(): ?self
    {
        return match($this) {
            self::Pending           => self::PendingManager,
            self::PendingManager    => self::PendingDepartment,
            self::PendingDepartment => self::PendingHr,
            self::PendingHr         => self::PendingPayroll,
            self::PendingPayroll    => self::Approved,
            default                 => null,
        };
    }
}
