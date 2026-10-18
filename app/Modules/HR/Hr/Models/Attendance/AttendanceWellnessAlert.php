<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceWellnessAlert extends Model
{
    protected $fillable = [
        'employee_id', 'alert_type', 'severity', 'severity_color',
        'period_start', 'period_end', 'description', 'evidence',
        'ai_insight', 'status', 'manager_notes',
        'acknowledged_by', 'acknowledged_at',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'evidence'         => 'array',
        'acknowledged_at'  => 'datetime',
    ];

    const TYPES = [
        'stress_indicator'  => 'Stress Indicator',
        'burnout_signal'    => 'Burnout Signal',
        'withdrawal'        => 'Attendance Withdrawal',
        'overtime_overload' => 'Overtime Overload',
        'trend_decline'     => 'Attendance Trend Decline',
        'department_risk'   => 'Department-Wide Risk',
    ];

    const TYPE_ICONS = [
        'stress_indicator'  => 'fas fa-brain',
        'burnout_signal'    => 'fas fa-fire',
        'withdrawal'        => 'fas fa-door-open',
        'overtime_overload' => 'fas fa-hourglass-end',
        'trend_decline'     => 'fas fa-trending-down',
        'department_risk'   => 'fas fa-building',
    ];

    const SEVERITIES = ['low', 'medium', 'high', 'critical'];
    const SEVERITY_COLORS = ['low' => 'info', 'medium' => 'warning', 'high' => 'error', 'critical' => 'deep-purple'];
    const STATUS_COLORS   = ['open' => 'warning', 'acknowledged' => 'info', 'resolved' => 'success', 'dismissed' => 'secondary'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopeOpen($query)       { return $query->where('status', 'open'); }
    public function scopeHighPriority($query) { return $query->whereIn('severity', ['high', 'critical']); }
}
