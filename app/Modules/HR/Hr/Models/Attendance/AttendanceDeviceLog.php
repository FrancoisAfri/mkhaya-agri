<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceDeviceLog extends Model
{
    protected $table = 'attendance_device_logs';

    protected $fillable = [
        'device_id', 'device_type', 'device_name', 'employee_id', 'clocking_id',
        'raw_data', 'status', 'error_message', 'captured_at', 'processed_at',
    ];

    protected $casts = [
        'raw_data'     => 'array',
        'captured_at'  => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const TYPES    = ['biometric', 'facial_recognition', 'qr_scanner', 'mobile'];
    public const STATUSES = ['pending', 'processed', 'failed'];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function clocking()
    {
        return $this->belongsTo(AttendanceClocking::class, 'clocking_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function markProcessed(int $clockingId): void
    {
        $this->update([
            'status'       => 'processed',
            'clocking_id'  => $clockingId,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status'        => 'failed',
            'error_message' => $reason,
            'processed_at'  => now(),
        ]);
    }
}
