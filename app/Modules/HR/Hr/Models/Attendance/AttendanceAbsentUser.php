<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAbsentUser extends Model
{
    protected $table = 'attendance_absent_users';

    protected $fillable = [
        'employee_id', 'date', 'email_sent',
        'is_applied', 'is_deducted', 'source',
    ];

    protected $casts = [
        'date'        => 'date',
        'email_sent'  => 'boolean',
        'is_applied'  => 'boolean',
        'is_deducted' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function scopePending($query)
    {
        return $query->where('is_applied', false)->where('is_deducted', false);
    }
}
