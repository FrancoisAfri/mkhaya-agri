<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** One revision of a rostered shift, before and after. */
class ShiftChange extends SitedModel
{
    protected $table = 'attendance_shift_changes';

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
