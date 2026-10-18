<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceClocking extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_clockings';

    protected $fillable = [
        'employee_id', 'attendance_record_id', 'type', 'method', 'work_type',
        'clocked_at', 'location', 'latitude', 'longitude', 'device_id',
        'ip_address', 'notes', 'is_verified', 'is_missed', 'is_corrected',
        'corrected_by', 'corrected_at',
    ];

    protected $casts = [
        'clocked_at'   => 'datetime',
        'corrected_at' => 'datetime',
        'is_verified'  => 'boolean',
        'is_missed'    => 'boolean',
        'is_corrected' => 'boolean',
        'latitude'     => 'float',
        'longitude'    => 'float',
    ];

    public const TYPES   = ['clock_in', 'clock_out', 'break_start', 'break_end'];
    public const METHODS = ['manual', 'biometric', 'facial', 'gps', 'qr_code', 'mobile'];
    public const WORK_TYPES = ['office', 'remote', 'hybrid'];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function attendanceRecord()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function correctedBy()
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function gpsLog()
    {
        return $this->hasOne(AttendanceGpsLog::class, 'clocking_id');
    }

    public function biometricLog()
    {
        return $this->hasOne(AttendanceBiometricLog::class, 'clocking_id');
    }

    public function scopeClockIns($query)
    {
        return $query->where('type', 'clock_in');
    }

    public function scopeClockOuts($query)
    {
        return $query->where('type', 'clock_out');
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('clocked_at', $date);
    }

    public function scopeMissed($query)
    {
        return $query->where('is_missed', true);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
