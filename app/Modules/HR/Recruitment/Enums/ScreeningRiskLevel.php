<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ScreeningRiskLevel: string
{
    case Low      = 'low';
    case Moderate = 'moderate';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Low      => 'Low',
            self::Moderate => 'Moderate',
            self::High     => 'High',
            self::Critical => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Low      => 'success',
            self::Moderate => 'warning',
            self::High     => 'error',
            self::Critical => 'error',
        };
    }

    /**
     * Relative severity weight — for sorting / aggregating risk, never for an
     * automated hire/reject decision.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low      => 1,
            self::Moderate => 2,
            self::High     => 3,
            self::Critical => 4,
        };
    }
}
