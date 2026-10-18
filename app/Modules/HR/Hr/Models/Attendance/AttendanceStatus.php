<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceStatus extends Model
{
    use SoftDeletes;

    protected $table = 'attendance_statuses';

    protected $fillable = [
        'name', 'code', 'color',
        'is_paid', 'is_present', 'is_absent', 'is_late', 'is_leave', 'is_public_holiday',
        'status',
    ];

    protected $casts = [
        'is_paid'           => 'boolean',
        'is_present'        => 'boolean',
        'is_absent'         => 'boolean',
        'is_late'           => 'boolean',
        'is_leave'          => 'boolean',
        'is_public_holiday' => 'boolean',
    ];

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'status_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
