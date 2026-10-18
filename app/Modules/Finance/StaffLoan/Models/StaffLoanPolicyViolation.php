<?php

namespace App\Modules\Finance\StaffLoan\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffLoanPolicyViolation extends Model
{
    protected $fillable = [
        'uuid', 'loan_id', 'advance_id', 'employee_id',
        'violation_type', 'violation_description', 'severity', 'policy_rule',
        'metadata', 'status', 'detected_at', 'resolved_by', 'resolved_at', 'resolution_notes',
    ];

    protected $casts = [
        'metadata'    => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn(self $v) => $v->uuid ??= (string) Str::uuid());
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(StaffLoan::class, 'loan_id');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'advance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
