<?php

namespace App\Modules\HR\Recruitment\Enums;

enum RequisitionStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case Rejected        = 'rejected';
    case Cancelled       = 'cancelled';
    case Converted       = 'converted';
    case Expired         = 'expired';

    public function label(): string
    {
        return match($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
            self::Cancelled       => 'Cancelled',
            self::Converted       => 'Converted',
            self::Expired         => 'Expired',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft           => 'default',
            self::PendingApproval => 'warning',
            self::Approved        => 'success',
            self::Rejected        => 'error',
            self::Cancelled       => 'secondary',
            self::Converted       => 'info',
            self::Expired         => 'secondary',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Cancelled,
            self::Converted,
            self::Expired,
        ]);
    }

    public function isApprovable(): bool
    {
        return $this === self::PendingApproval;
    }

    public function canConvert(): bool
    {
        return $this === self::Approved;
    }
}
