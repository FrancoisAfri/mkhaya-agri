<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ViolationPattern
 * 
 * Tracks aggregated violation patterns per user
 */
class ViolationPattern extends Model
{
    protected $table = 'policy_violation_patterns';

    protected $fillable = [
        'user_id',
        'violation_count',
        'critical_count',
        'high_count',
        'medium_count',
        'total_severity_score',
        'near_miss_count',
        'approval_override_count',
        'last_violation_at',
    ];

    protected $casts = [
        'last_violation_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }
}

/**
 * NearMissIncident
 * 
 * Tracks near-miss incidents (warnings, escalations without actual violation)
 */
class NearMissIncident extends Model
{
    protected $table = 'policy_near_misses';

    protected $fillable = [
        'user_id',
        'rule_id',
        'policy_id',
        'module',
        'action',
        'reason',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}

/**
 * ApprovalOverride
 * 
 * Tracks when exceptions were granted to override rules
 */
class ApprovalOverride extends Model
{
    protected $table = 'policy_approval_overrides';

    protected $fillable = [
        'user_id',
        'rule_id',
        'policy_id',
        'violation_count',
        'approved_by',
        'reason',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }
}

/**
 * RepeatViolation
 * 
 * Tracks when a user violates the same rule multiple times
 */
class RepeatViolation extends Model
{
    protected $table = 'policy_repeat_violations';

    protected $fillable = [
        'violation_id',
        'user_id',
        'rule_id',
        'prior_count',
    ];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(PolicyViolation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class);
    }
}

/**
 * RiskProfile
 * 
 * Current risk scores for users and departments
 */
class RiskProfile extends Model
{
    protected $table = 'policy_risk_profiles';

    protected $fillable = [
        'user_id',
        'department_id',
        'risk_score',
        'risk_category',
        'breakdown',
        'calculated_at',
    ];

    protected $casts = [
        'breakdown' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Department::class);
    }
}

/**
 * RiskProfileHistory
 * 
 * Historical tracking of risk score changes over time
 */
class RiskProfileHistory extends Model
{
    protected $table = 'policy_risk_profile_history';

    protected $fillable = [
        'user_id',
        'department_id',
        'risk_score',
        'risk_category',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Department::class);
    }
}

/**
 * RiskAlert
 * 
 * Alerts triggered when risk thresholds exceeded
 */
class RiskAlert extends Model
{
    protected $table = 'policy_risk_alerts';

    protected $fillable = [
        'user_id',
        'department_id',
        'risk_score',
        'alert_type',
        'status',
        'assigned_to',
        'notes',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Department::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to');
    }
}

/**
 * RiskThreshold
 * 
 * Configurable risk thresholds
 */
class RiskThreshold extends Model
{
    protected $table = 'policy_risk_thresholds';

    protected $fillable = [
        'threshold_name',
        'score',
        'description',
        'actions',
    ];

    protected $casts = [
        'actions' => 'array',
    ];
}
