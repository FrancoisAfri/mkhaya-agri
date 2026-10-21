<?php

namespace App\Modules\HR\Hr\Attendance\Models;

class OvertimeRequest extends SitedModel
{
    protected $table = 'attendance_overtime_requests';

    protected $casts = ['work_date' => 'date', 'emergency' => 'boolean', 'reviewed_at' => \App\Modules\HR\Hr\Attendance\Support\SiteDateTime::class];
}
