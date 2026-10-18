<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceShift extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_shifts';

    protected $fillable = [
        'name', 'code', 'start_time', 'end_time',
        'break_duration_minutes', 'is_overnight', 'is_flexible',
        'flexible_window_minutes', 'grace_period_minutes',
        'description', 'status',
    ];

    protected $casts = [
        'is_overnight' => 'boolean',
        'is_flexible'  => 'boolean',
    ];

    public function calendars()
    {
        return $this->belongsToMany(AttendanceWorkCalendar::class, 'attendance_calendar_shift', 'shift_id', 'calendar_id');
    }

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'shift_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function getNetHoursAttribute(): float
    {
        $start = \Carbon\Carbon::parse($this->start_time);
        $end   = \Carbon\Carbon::parse($this->end_time);
        if ($this->is_overnight && $end->lt($start)) {
            $end->addDay();
        }
        return round(($start->diffInMinutes($end) - $this->break_duration_minutes) / 60, 2);
    }
}
