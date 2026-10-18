<?php

namespace App\Modules\HR\PreBoarding\Enums;

enum HrPersonStatus: string
{
    case PreBoarding = 'pre_boarding';
    case Onboarding  = 'onboarding';
    case Active      = 'active';
    case Terminated  = 'terminated';
    case Resigned    = 'resigned';

    public function label(): string
    {
        return match($this) {
            self::PreBoarding => 'Pre-Boarding',
            self::Onboarding  => 'Onboarding',
            self::Active      => 'Active',
            self::Terminated  => 'Terminated',
            self::Resigned    => 'Resigned',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PreBoarding => 'warning',
            self::Onboarding  => 'info',
            self::Active      => 'success',
            self::Terminated  => 'error',
            self::Resigned    => 'default',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::PreBoarding, self::Onboarding, self::Active]);
    }
}
