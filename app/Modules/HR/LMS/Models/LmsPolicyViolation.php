<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsPolicyViolation extends Model
{
    protected $table = 'lms_policy_violations';

    protected $fillable = [
        'enrollment_id', 'assignment_id', 'attempt_id', 'understanding_score_id',
        'policy_violation_id',
        'rule_name', 'violation_type', 'severity', 'action_taken',
        'description', 'payload_snapshot',
        'actor_id',
        'is_resolved', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'is_resolved'      => 'boolean',
        'resolved_at'      => 'datetime',
    ];

    const VIOLATION_TYPES = [
        'mandatory_training_incomplete' => 'Mandatory Training Incomplete',
        'cert_expired'                  => 'Certificate Expired',
        'pass_mark_not_met'             => 'Pass Mark Not Met',
        'understanding_below_threshold' => 'Understanding Below Threshold',
        'restricted_action_blocked'     => 'Restricted Action Blocked',
        'custom'                        => 'Custom',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LmsComplianceAssignment::class, 'assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical' => 'error',
            'high'     => 'deep-orange',
            'medium'   => 'warning',
            default    => 'info',
        };
    }

    public function actionColor(): string
    {
        return match ($this->action_taken) {
            'blocked'   => 'error',
            'escalated' => 'warning',
            'warned'    => 'orange',
            default     => 'grey',
        };
    }
}
