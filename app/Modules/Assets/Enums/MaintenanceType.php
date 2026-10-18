<?php

namespace App\Modules\Assets\Enums;

enum MaintenanceType: string
{
    case Preventive  = 'preventive';
    case Corrective  = 'corrective';
    case Predictive  = 'predictive';
    case Calibration = 'calibration';
    case Inspection  = 'inspection';

    public function label(): string
    {
        return match($this) {
            self::Preventive  => 'Preventive',
            self::Corrective  => 'Corrective',
            self::Predictive  => 'Predictive',
            self::Calibration => 'Calibration',
            self::Inspection  => 'Inspection',
        };
    }
}
