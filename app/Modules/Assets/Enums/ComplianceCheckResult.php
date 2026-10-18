<?php

namespace App\Modules\Assets\Enums;

enum ComplianceCheckResult: string
{
    case Pass          = 'pass';
    case Fail          = 'fail';
    case Overdue       = 'overdue';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match($this) {
            self::Pass          => 'Pass',
            self::Fail          => 'Fail',
            self::Overdue       => 'Overdue',
            self::NotApplicable => 'N/A',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pass          => 'success',
            self::Fail          => 'error',
            self::Overdue       => 'warning',
            self::NotApplicable => 'default',
        };
    }
}
