<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyRuleAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'policy_rule_audit_log';

    protected $fillable = [
        'leave_request_id', 'employee_id', 'policy_rule_id',
        'event_type', 'rule_code', 'evaluation_result', 'evaluation_detail',
        'override_requested_by', 'override_approved_by', 'override_reason',
        'ai_used', 'ai_explanation', 'created_at',
    ];

    protected $casts = [
        'ai_used'    => 'boolean',
        'created_at' => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function policyRule(): BelongsTo
    {
        return $this->belongsTo(PolicyLeaveRule::class, 'policy_rule_id');
    }
}
