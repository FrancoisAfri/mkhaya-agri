<?php

namespace App\Core\Compliance\Models;

use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Core\User\Models\User;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records a personal-information breach incident.
 *
 * POPIA §22 requires notification to the Information Regulator and affected
 * data subjects as soon as reasonably possible — the spec states 72 hours.
 *
 * Statuses:
 *   open          – breach detected, investigation in progress
 *   notified      – Information Regulator + practice owner notified
 *   contained     – breach contained, remediation under way
 *   closed        – fully resolved and documented
 */
class DataBreachIncident extends Model
{
    use Uuids, LogsActivity;

    protected $table = 'data_breach_incidents';

    protected $fillable = [
        'uuid',
        'title',
        'description',
        'severity',           // low | medium | high | critical
        'status',
        'detected_at',
        'notified_regulator_at',
        'notified_subjects_at',
        'contained_at',
        'closed_at',
        'affected_record_count',
        'affected_subject_types',
        'reported_by',
        'assigned_to',
        'remediation_notes',
        'regulator_reference',
    ];

    protected $casts = [
        'detected_at'             => 'datetime',
        'notified_regulator_at'   => 'datetime',
        'notified_subjects_at'    => 'datetime',
        'contained_at'            => 'datetime',
        'closed_at'               => 'datetime',
        'affected_subject_types'  => 'array',
        'affected_record_count'   => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Compliance')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function hoursUntilDeadline(): float
    {
        // 72-hour notification deadline from detection
        $deadline = $this->detected_at->addHours(72);
        return max(0, now()->diffInHours($deadline, false));
    }

    public function isOverdue(): bool
    {
        return is_null($this->notified_regulator_at)
            && $this->detected_at->addHours(72)->isPast();
    }
}
