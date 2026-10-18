<?php

namespace App\Modules\Assets\Enums;

enum IncidentSeverity: string
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

    public function color(): string
    {
        return match($this) {
            self::Low      => 'success',
            self::Medium   => 'warning',
            self::High     => 'error',
            self::Critical => 'error',
        };
    }
}
