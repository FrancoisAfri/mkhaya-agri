<?php

namespace App\Modules\Assets\Enums;

enum MisuseFlagType: string
{
    case AfterHoursUsage      = 'after_hours_usage';
    case UnauthorizedLocation = 'unauthorized_location';
    case RapidMovement        = 'rapid_movement';
    case RepeatedIncidents    = 'repeated_incidents';
    case Other                = 'other';

    public function label(): string
    {
        return match($this) {
            self::AfterHoursUsage      => 'After-Hours Usage',
            self::UnauthorizedLocation => 'Unauthorized Location',
            self::RapidMovement        => 'Rapid Movement',
            self::RepeatedIncidents    => 'Repeated Incidents',
            self::Other                => 'Other',
        };
    }
}
