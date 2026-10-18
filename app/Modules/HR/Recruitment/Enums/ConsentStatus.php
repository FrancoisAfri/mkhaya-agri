<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ConsentStatus: string
{
    case Pending  = 'pending';
    case Granted  = 'granted';
    case Declined = 'declined';
    case Revoked  = 'revoked';

    public function label(): string
    {
        return match($this) {
            self::Pending  => 'Pending',
            self::Granted  => 'Granted',
            self::Declined => 'Declined',
            self::Revoked  => 'Revoked',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending  => 'warning',
            self::Granted  => 'success',
            self::Declined => 'error',
            self::Revoked  => 'default',
        };
    }
}
