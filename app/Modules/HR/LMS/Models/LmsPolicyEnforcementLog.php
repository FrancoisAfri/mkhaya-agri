<?php

namespace App\Modules\HR\LMS\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsPolicyEnforcementLog extends Model
{
    protected $table = 'lms_policy_enforcement_logs';

    protected $fillable = [
        'enrollment_id', 'assignment_id', 'attempt_id',
        'action_checked',
        'rules_evaluated', 'violations_found',
        'was_blocked', 'required_escalation', 'passed_clean',
        'payload', 'enforcement_summary', 'violation_ids',
        'actor_id', 'checked_at',
    ];

    protected $casts = [
        'payload'             => 'array',
        'enforcement_summary' => 'array',
        'violation_ids'       => 'array',
        'was_blocked'         => 'boolean',
        'required_escalation' => 'boolean',
        'passed_clean'        => 'boolean',
        'checked_at'          => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(LmsEnrollment::class, 'enrollment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function statusColor(): string
    {
        if ($this->was_blocked)          return 'error';
        if ($this->required_escalation)  return 'warning';
        if ($this->violations_found > 0) return 'orange';
        return 'success';
    }

    public function statusLabel(): string
    {
        if ($this->was_blocked)          return 'Blocked';
        if ($this->required_escalation)  return 'Escalated';
        if ($this->violations_found > 0) return 'Warned';
        return 'Passed';
    }
}
