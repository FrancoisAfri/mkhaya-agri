<?php

namespace App\Modules\HR\Onboarding\Enums;

enum ProvisioningTaskStatus: string
{
    case Pending    = 'pending';
    case Requested  = 'requested';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::Requested  => 'Requested',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending    => 'warning',
            self::Requested  => 'info',
            self::InProgress => 'primary',
            self::Completed  => 'success',
            self::Cancelled  => 'default',
        };
    }
}
