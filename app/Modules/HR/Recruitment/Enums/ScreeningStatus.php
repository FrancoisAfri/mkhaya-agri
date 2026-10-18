<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ScreeningStatus: string
{
    case Pending           = 'pending';
    case Running           = 'running';
    case Completed         = 'completed';
    case Failed            = 'failed';
    case SkippedNoConsent  = 'skipped_no_consent';

    public function label(): string
    {
        return match ($this) {
            self::Pending          => 'Pending',
            self::Running          => 'Running',
            self::Completed        => 'Completed',
            self::Failed           => 'Failed',
            self::SkippedNoConsent => 'Skipped — No Consent',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending          => 'default',
            self::Running          => 'info',
            self::Completed        => 'success',
            self::Failed           => 'error',
            self::SkippedNoConsent => 'warning',
        };
    }
}
