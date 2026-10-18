<?php

namespace App\Modules\Assets\Enums;

enum RiskLevel: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match($this) {
            self::Low      => 'Low',
            self::Medium   => 'Medium',
            self::High     => 'High',
            self::Critical => 'Critical',
        };
    }

    public static function fromScore(float $score): self
    {
        return match(true) {
            $score >= 80 => self::Critical,
            $score >= 60 => self::High,
            $score >= 40 => self::Medium,
            default      => self::Low,
        };
    }
}
