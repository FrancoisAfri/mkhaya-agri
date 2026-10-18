<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceRecord extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_records';

    protected $fillable = [
        'employee_id', 'status_id', 'shift_id', 'date',
        'clock_in', 'clock_out', 'hours_worked', 'overtime_hours',
        'is_late', 'late_minutes', 'is_early_departure', 'early_departure_minutes',
        'source', 'notes', 'manager_approved', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'date'         => 'date',
        'clock_in'     => 'datetime',
        'clock_out'    => 'datetime',
        'approved_at'  => 'datetime',
        'is_late'      => 'boolean',
        'is_early_departure' => 'boolean',
        'manager_approved'   => 'boolean',
        'hours_worked'       => 'float',
        'overtime_hours'     => 'float',
    ];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function status()
    {
        return $this->belongsTo(AttendanceStatus::class, 'status_id');
    }

    public function shift()
    {
        return $this->belongsTo(AttendanceShift::class, 'shift_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('manager_approved', false);
    }
}
