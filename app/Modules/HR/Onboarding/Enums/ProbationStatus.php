<?php

namespace App\Modules\HR\Onboarding\Enums;

enum ProbationStatus: string
{
    case Active    = 'active';
    case Completed = 'completed';
    case Extended  = 'extended';
    case Failed    = 'failed';

    public function label(): string
    {
        return match($this) {
            self::Active    => 'Active',
            self::Completed => 'Completed',
            self::Extended  => 'Extended',
            self::Failed    => 'Failed',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Active    => 'info',
            self::Completed => 'success',
            self::Extended  => 'warning',
            self::Failed    => 'error',
        };
    }
}
