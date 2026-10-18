<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisciplinaryPolicyViolation extends Model
{
    protected $table = 'disciplinary_policy_violations';

    protected $fillable = [
        'case_id', 'hearing_id', 'investigation_id',
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

    public function case(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryCase::class, 'case_id');
    }

    public function hearing(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryHearing::class, 'hearing_id');
    }

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(DisciplinaryInvestigation::class, 'investigation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(DisciplinaryPolicyException::class, 'violation_id');
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
