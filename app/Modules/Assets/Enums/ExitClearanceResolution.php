<?php

namespace App\Modules\Assets\Enums;

enum ExitClearanceResolution: string
{
    case Pending    = 'pending';
    case Returned   = 'returned';
    case Transferred = 'transferred';
    case Waived     = 'waived';

    public function label(): string
    {
        return match($this) {
            self::Pending     => 'Pending',
            self::Returned    => 'Returned',
            self::Transferred => 'Transferred',
            self::Waived      => 'Waived',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending     => 'warning',
            self::Returned    => 'success',
            self::Transferred => 'info',
            self::Waived      => 'default',
        };
    }
}
