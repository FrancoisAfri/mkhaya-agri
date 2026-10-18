<?php

namespace App\Modules\Assets\Enums;

enum AssignmentType: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Loan      = 'loan';

    public function label(): string
    {
        return match($this) {
            self::Permanent => 'Permanent',
            self::Temporary => 'Temporary',
            self::Loan      => 'Loan',
        };
    }
}
