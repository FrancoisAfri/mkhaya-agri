<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;

class AttendanceBiometricLog extends Model
{
    protected $table = 'attendance_biometric_logs';

    protected $fillable = [
        'employee_id', 'clocking_id', 'biometric_type', 'device_id',
        'confidence_score', 'template_hash', 'raw_response',
        'is_matched', 'is_liveness_verified', 'failure_reason', 'captured_at',
    ];

    protected $casts = [
        'raw_response'         => 'array',
        'confidence_score'     => 'float',
        'is_matched'           => 'boolean',
        'is_liveness_verified' => 'boolean',
        'captured_at'          => 'datetime',
    ];

    public const TYPES = ['fingerprint', 'facial', 'iris', 'qr_code'];

    public function employee()
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function clocking()
    {
        return $this->belongsTo(AttendanceClocking::class, 'clocking_id');
    }

    public function scopeMatched($query)
    {
        return $query->where('is_matched', true);
    }

    public function scopeFailed($query)
    {
        return $query->where('is_matched', false);
    }

    public function isHighConfidence(float $threshold = 85.0): bool
    {
        return $this->confidence_score !== null && $this->confidence_score >= $threshold;
    }
}
