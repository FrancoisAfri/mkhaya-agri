<?php

namespace App\Modules\Assets\Enums;

enum ExtractionStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Extracted  = 'extracted';
    case Failed     = 'failed';
    case Confirmed  = 'confirmed';
    case Rejected   = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::Processing => 'Processing',
            self::Extracted  => 'Extracted',
            self::Failed     => 'Failed',
            self::Confirmed  => 'Confirmed',
            self::Rejected   => 'Rejected',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending    => 'default',
            self::Processing => 'info',
            self::Extracted  => 'warning',
            self::Failed     => 'error',
            self::Confirmed  => 'success',
            self::Rejected   => 'error',
        };
    }
}
