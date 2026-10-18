<?php

namespace App\Modules\HR\Payroll\Enums;

enum PayrollRunStatus: string
{
    case Draft      = 'draft';
    case Processing = 'processing';
    case Review     = 'review';
    case Released   = 'released';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft      => 'Draft',
            self::Processing => 'Processing',
            self::Review     => 'Review',
            self::Released   => 'Released',
            self::Cancelled  => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft      => 'secondary',
            self::Processing => 'info',
            self::Review     => 'warning',
            self::Released   => 'success',
            self::Cancelled  => 'error',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Released, self::Cancelled]);
    }

    public function canRelease(): bool
    {
        return $this === self::Review;
    }
}
