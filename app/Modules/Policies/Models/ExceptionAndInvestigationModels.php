<?php

namespace App\Modules\Policies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * PolicyException
 * 
 * Exception requests to bypass policy rules
 */
class PolicyException extends Model
{
    use SoftDeletes;

    protected $table = 'policy_exceptions';

    protected $fillable = [
        'user_id',
        'policy_id',
        'rule_id',
        'reason',
        'start_date',
        'end_date',
        'evidence',
        'duration_days',
        'status',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejection_reason',
        'auto_notify_before_expiry',
        'expiry_notified_at',
        'expired_at',
        'violation_count',
        'usage_count',
    ];

    protected $casts = [
        'evidence' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'expiry_notified_at' => 'datetime',
        'expired_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PolicyRule::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'approved_by');
    }

    /**
     * Check if exception is still valid
     */
    public function isValid(): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        $now = Carbon::today();
        if ($this->start_date && $now < $this->start_date) {
            return false;
        }

        if ($this->end_date && $now > $this->end_date) {
            return false;
        }

        return true;
    }

    /**
     * Check if exception is expired
     */
    public function isExpired(): bool
    {
        if (!$this->end_date) {
            return false;
        }

        return Carbon::today() > $this->end_date;
    }

    /**
     * Get days remaining
     */
    public function daysRemaining(): ?int
    {
        if (!$this->end_date) {
            return null;
        }

        return max(0, Carbon::today()->diffInDays($this->end_date));
    }
}

/**
 * ViolationInvestigation
 * 
 * Investigation details for policy violations
 */
class ViolationInvestigation extends Model
{
    protected $table = 'policy_violation_investigations';

    protected $fillable = [
        'violation_id',
        'policy_id',
        'user_id',
        'status',
        'assigned_to',
        'assigned_at',
        'initial_notes',
        'findings',
        'severity_finding',
        'resolution_action',
        'resolution_type',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'resolved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function violation(): BelongsTo
    {
        return $this->belongsTo(PolicyViolation::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'resolved_by');
    }

    public function notes()
    {
        return $this->hasMany(InvestigationNote::class);
    }

    public function evidence()
    {
        return $this->hasMany(InvestigationEvidence::class);
    }
}

/**
 * InvestigationNote
 * 
 * Notes added during investigation
 */
class InvestigationNote extends Model
{
    protected $table = 'policy_investigation_notes';

    protected $fillable = [
        'investigation_id',
        'created_by',
        'note',
        'attachments',
        'visibility',
    ];

    protected $casts = [
        'attachments' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(ViolationInvestigation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }
}

/**
 * InvestigationEvidence
 * 
 * Evidence/attachments for investigation
 */
class InvestigationEvidence extends Model
{
    protected $table = 'policy_investigation_evidence';

    protected $fillable = [
        'investigation_id',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'description',
        'uploaded_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(ViolationInvestigation::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'uploaded_by');
    }
}
