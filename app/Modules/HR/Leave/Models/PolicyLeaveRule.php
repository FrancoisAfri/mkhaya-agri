<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyLeaveRule extends Model
{
    protected $table = 'policy_leave_rules';

    protected $fillable = [
        'policy_id', 'leave_type_id', 'rule_code', 'rule_name',
        'rule_description', 'rule_type', 'rule_config', 'priority',
        'overrides_bcea', 'requires_policy_override', 'active',
        'effective_from', 'effective_to', 'created_by',
    ];

    protected $casts = [
        'rule_config'              => 'array',
        'overrides_bcea'           => 'boolean',
        'requires_policy_override' => 'boolean',
        'active'                   => 'boolean',
        'effective_from'           => 'date',
        'effective_to'             => 'date',
        'priority'                 => 'integer',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)
            ->where(fn($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', today()))
            ->where(fn($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()));
    }

    public function scopeForLeaveType($query, int $leaveTypeId)
    {
        return $query->where(fn($q) => $q->whereNull('leave_type_id')->orWhere('leave_type_id', $leaveTypeId));
    }
}
