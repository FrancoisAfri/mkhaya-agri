<?php

namespace App\Modules\Assets\Enums;

enum MisuseFlagStatus: string
{
    case Open          = 'open';
    case Investigating = 'investigating';
    case Dismissed     = 'dismissed';
    case Confirmed     = 'confirmed';

    public function label(): string
    {
        return match($this) {
            self::Open          => 'Open',
            self::Investigating => 'Investigating',
            self::Dismissed     => 'Dismissed',
            self::Confirmed     => 'Confirmed',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Open          => 'warning',
            self::Investigating => 'info',
            self::Dismissed     => 'default',
            self::Confirmed     => 'error',
        };
    }
}
