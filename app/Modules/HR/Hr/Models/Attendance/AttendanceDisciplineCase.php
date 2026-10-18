<?php

namespace App\Modules\HR\Hr\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceDisciplineCase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'case_number', 'case_type', 'status',
        'severity', 'severity_color', 'period_start', 'period_end',
        'summary', 'violations', 'ai_recommendation', 'resolution_notes',
        'assigned_to', 'created_by', 'resolved_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'violations'   => 'array',
        'resolved_at'  => 'datetime',
    ];

    const CASE_TYPES = [
        'verbal_warning'  => 'Verbal Warning',
        'written_warning' => 'Written Warning',
        'final_warning'   => 'Final Warning',
        'suspension'      => 'Suspension',
        'dismissal'       => 'Dismissal',
    ];

    const STATUS_COLORS = [
        'pending'     => 'warning',
        'in_progress' => 'info',
        'resolved'    => 'success',
        'closed'      => 'secondary',
        'dismissed'   => 'secondary',
    ];

    const SEVERITY_COLORS = ['low' => 'info', 'medium' => 'warning', 'high' => 'error', 'critical' => 'deep-purple'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\HR\Hr\Models\HRPerson::class, 'employee_id');
    }

    public function packs(): HasMany
    {
        return $this->hasMany(AttendanceDisciplinePack::class, 'discipline_case_id');
    }

    public function getStatusColorAttribute(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'grey';
    }

    public function scopePending($query) { return $query->where('status', 'pending'); }
    public function scopeOpen($query)    { return $query->whereIn('status', ['pending', 'in_progress']); }
}
