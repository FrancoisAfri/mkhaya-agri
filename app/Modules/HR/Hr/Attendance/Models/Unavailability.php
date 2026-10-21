<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** A stretch when a worker cannot be rostered. */
class Unavailability extends EngineModel
{
    protected $table = 'attendance_unavailability';

    protected $casts = ['starts_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class, 'ends_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];
}
