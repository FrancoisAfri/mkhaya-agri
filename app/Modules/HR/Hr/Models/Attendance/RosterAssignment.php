<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Shared\Traits\KeepsDatabaseDefaults;

class RosterAssignment extends Model
{
    use KeepsDatabaseDefaults;

    protected $fillable = [
        'roster_schedule_id', 'employee_id', 'shift_id',
        'assigned_date', 'status', 'is_ai_suggested', 'is_rest_day',
        'swap_with_employee', 'swap_reason', 'notes',
    ];

    protected $casts = [
        'assigned_date'   => 'date',
        'is_ai_suggested' => 'boolean',
        'is_rest_day'     => 'boolean',
    ];

    const STATUSES = ['scheduled', 'confirmed', 'swapped', 'cancelled', 'absent'];
    const STATUS_COLORS = [
        'scheduled' => 'info',
        'confirmed' => 'success',
        'swapped'   => 'warning',
        'cancelled' => 'secondary',
        'absent'    => 'error',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(RosterSchedule::class, 'roster_schedule_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShift::class, 'shift_id');
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'grey';
    }
}
