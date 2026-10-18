<?php

namespace App\Modules\Assets\Enums;

enum ComplianceRuleType: string
{
    case MaintenanceInterval = 'maintenance_interval';
    case SoftwareCheck       = 'software_check';
    case Inspection          = 'inspection';
    case Certification       = 'certification';
    case Other               = 'other';

    public function label(): string
    {
        return match($this) {
            self::MaintenanceInterval => 'Maintenance Interval',
            self::SoftwareCheck       => 'Software Check',
            self::Inspection          => 'Inspection',
            self::Certification       => 'Certification',
            self::Other               => 'Other',
        };
    }
}
