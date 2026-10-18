<?php

namespace App\Modules\Disciplinary\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryPolicyEnforcementLog extends Model
{
    protected $table = 'disciplinary_policy_enforcement_logs';

    protected $fillable = [
        'case_id', 'hearing_id', 'investigation_id',
        'action_checked',
        'rules_evaluated', 'violations_found',
        'was_blocked', 'required_escalation', 'passed_clean',
        'payload', 'enforcement_summary', 'violation_ids',
        'actor_id', 'checked_at',
    ];

    protected $casts = [
        'payload'            => 'array',
        'enforcement_summary'=> 'array',
        'violation_ids'      => 'array',
        'was_blocked'        => 'boolean',
        'required_escalation'=> 'boolean',
        'passed_clean'       => 'boolean',
        'checked_at'         => 'datetime',
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

    public function statusColor(): string
    {
        if ($this->was_blocked)         return 'error';
        if ($this->required_escalation) return 'warning';
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
