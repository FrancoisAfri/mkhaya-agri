<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeLeaveOverride extends Model
{
    protected $table = 'employee_leave_overrides';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_cycle_id',
        'override_hours', 'reason', 'applied_by',
        'active', 'effective_from', 'effective_to',
    ];

    protected $casts = [
        'override_hours' => 'decimal:2',
        'active'         => 'boolean',
        'effective_from' => 'date',
        'effective_to'   => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
