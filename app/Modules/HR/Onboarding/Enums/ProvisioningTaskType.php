<?php

namespace App\Modules\HR\Onboarding\Enums;

enum ProvisioningTaskType: string
{
    case Laptop         = 'laptop';
    case EmailAccount   = 'email_account';
    case SystemAccess   = 'system_access';
    case BuildingAccess = 'building_access';
    case PayrollProfile = 'payroll_profile';
    case Phone          = 'phone';
    case Other          = 'other';

    public function label(): string
    {
        return match($this) {
            self::Laptop         => 'Laptop / Computer',
            self::EmailAccount   => 'Email Account',
            self::SystemAccess   => 'System Access',
            self::BuildingAccess => 'Building Access / Card',
            self::PayrollProfile => 'Payroll Profile',
            self::Phone          => 'Phone / SIM',
            self::Other          => 'Other',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Laptop         => 'mdi-laptop',
            self::EmailAccount   => 'mdi-email-outline',
            self::SystemAccess   => 'mdi-shield-key-outline',
            self::BuildingAccess => 'mdi-card-account-details-outline',
            self::PayrollProfile => 'mdi-currency-usd',
            self::Phone          => 'mdi-cellphone',
            self::Other          => 'mdi-checkbox-blank-outline',
        };
    }

    public function defaultDepartment(): string
    {
        return match($this) {
            self::Laptop, self::EmailAccount, self::SystemAccess => 'IT',
            self::BuildingAccess                                  => 'Facilities',
            self::PayrollProfile                                  => 'Finance',
            self::Phone                                           => 'IT',
            self::Other                                           => 'HR',
        };
    }
}
