<?php

namespace App\Modules\HR\Onboarding\Enums;

enum OnboardingDocumentStatus: string
{
    case Pending   = 'pending';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Submitted => 'Submitted',
            self::Approved  => 'Approved',
            self::Rejected  => 'Rejected',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending   => 'warning',
            self::Submitted => 'info',
            self::Approved  => 'success',
            self::Rejected  => 'error',
        };
    }
}
