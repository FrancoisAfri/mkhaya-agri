<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceBreak extends Model
{
    protected $table = 'attendance_breaks';

    protected $fillable = [
        'employee_id', 'attendance_record_id',
        'clock_in_clocking_id', 'clock_out_clocking_id',
        'break_type', 'break_start', 'break_end',
        'duration_minutes', 'is_open', 'notes',
        'latitude', 'longitude', 'location',
    ];

    protected $casts = [
        'break_start' => 'datetime',
        'break_end'   => 'datetime',
        'is_open'     => 'boolean',
    ];

    public const TYPES = ['lunch', 'tea', 'personal', 'other'];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function attendanceRecord()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function clockInClocking()
    {
        return $this->belongsTo(AttendanceClocking::class, 'clock_in_clocking_id');
    }

    public function clockOutClocking()
    {
        return $this->belongsTo(AttendanceClocking::class, 'clock_out_clocking_id');
    }

    public function scopeOpen($query)
    {
        return $query->where('is_open', true);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('break_start', $date);
    }

    public function computeDuration(): int
    {
        if (!$this->break_end) {
            return 0;
        }
        return (int) $this->break_start->diffInMinutes($this->break_end);
    }
}
