<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ShortlistStatus: string
{
    case Draft    = 'draft';
    case Pending  = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Draft    => 'Draft',
            self::Pending  => 'Pending Approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
