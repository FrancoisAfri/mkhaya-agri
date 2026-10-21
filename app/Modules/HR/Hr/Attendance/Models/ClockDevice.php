<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** A biometric reader, access-control gate or kiosk allowed to send clock events, with its own secret. */
class ClockDevice extends SitedModel
{
    public const KINDS = ['biometric', 'access_control', 'kiosk', 'sensor_gateway', 'telematics'];

    protected $table = 'attendance_clock_devices';

    protected $hidden = ['token_hash'];

    protected $casts = ['is_active' => 'boolean', 'last_seen_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];
}
