<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ContractStatus: string
{
    case Draft           = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';
    case SentForSigning  = 'sent_for_signing';
    case Signed          = 'signed';
    case Rejected        = 'rejected';
    case Expired         = 'expired';

    public function label(): string
    {
        return match($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::SentForSigning  => 'Sent for Signing',
            self::Signed          => 'Signed',
            self::Rejected        => 'Rejected',
            self::Expired         => 'Expired',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Signed, self::Rejected, self::Expired]);
    }
}
