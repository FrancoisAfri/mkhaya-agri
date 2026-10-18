<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceWorkCalendar extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_work_calendars';

    protected $fillable = [
        'name', 'year',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'include_public_holidays', 'description', 'is_default', 'status',
    ];

    protected $casts = [
        'monday'    => 'boolean',
        'tuesday'   => 'boolean',
        'wednesday' => 'boolean',
        'thursday'  => 'boolean',
        'friday'    => 'boolean',
        'saturday'  => 'boolean',
        'sunday'    => 'boolean',
        'include_public_holidays' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function shifts()
    {
        return $this->belongsToMany(AttendanceShift::class, 'attendance_calendar_shift', 'calendar_id', 'shift_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function getWorkingDaysAttribute(): array
    {
        $days = [];
        foreach (['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day) {
            if ($this->$day) {
                $days[] = ucfirst($day);
            }
        }
        return $days;
    }
}
