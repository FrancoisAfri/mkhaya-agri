<?php

namespace App\Modules\HR\Hr\Attendance\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends SitedModel
{
    protected $table = 'attendance_shift_assignments';

    public function shift(): BelongsTo
    {
        return $this->belongsTo(RosteredShift::class, 'shift_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id');
    }
}
