<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveAnomalyFlag extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveAnomaly')
            ->logOnly(['employee_id', 'anomaly_type', 'severity', 'status', 'false_positive',
                       'reviewed_by', 'reviewed_at', 'resolution_notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Anomaly flag #{$this->id} ({$this->anomaly_type?->value}) detected for employee #{$this->employee_id}",
                'updated' => $this->isDirty('status') && $this->status === 'resolved'
                    ? "Anomaly flag #{$this->id} resolved"
                    : "Anomaly flag #{$this->id} updated",
                'deleted' => "Anomaly flag #{$this->id} deleted",
                default   => "Anomaly flag #{$this->id}: {$e}",
            });
    }

    protected $table = 'leave_anomaly_flags';

    protected $fillable = [
        'employee_id', 'leave_request_id', 'anomaly_type', 'severity',
        'ai_model_version', 'ai_confidence_score', 'detection_method',
        'description', 'evidence', 'status',
        'reviewed_by', 'reviewed_at', 'resolution_notes', 'false_positive',
        'detected_at', 'alert_count', 'last_alerted_at',
    ];

    protected $casts = [
        'anomaly_type'       => LeaveAnomalyType::class,
        'evidence'           => 'array',
        'ai_confidence_score' => 'decimal:2',
        'false_positive'     => 'boolean',
        'reviewed_at'        => 'datetime',
        'detected_at'        => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }
}
