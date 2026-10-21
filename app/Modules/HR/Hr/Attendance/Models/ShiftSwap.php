<?php

namespace App\Modules\HR\Hr\Attendance\Models;

/** A request to hand an assigned shift to someone else, approved or rejected. */
class ShiftSwap extends SitedModel
{
    protected $table = 'attendance_shift_swaps';
}
