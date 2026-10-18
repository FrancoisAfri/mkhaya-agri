<?php

namespace App\Modules\Finance\StaffLoan\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Spatie\Activitylog\Traits\LogsActivity;

class SalaryAdvance extends Model
{
    use SoftDeletes;
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid', 'reference_number', 'employee_id', 'amount_requested', 'amount_approved',
        'monthly_net_pay', 'percentage_of_net', 'repayment_month', 'reason',
        'employment_status', 'is_permanent', 'probation_completed', 'is_suspended',
        'status', 'approved_by', 'approved_at', 'disbursed_at', 'settled_at',
        'rejection_reason', 'ai_flags', 'risk_score', 'risk_level', 'notes',
    ];

    protected $casts = [
        'amount_requested'  => 'decimal:2',
        'amount_approved'   => 'decimal:2',
        'monthly_net_pay'   => 'decimal:2',
        'percentage_of_net' => 'decimal:2',
        'is_permanent'      => 'boolean',
        'probation_completed'=> 'boolean',
        'is_suspended'      => 'boolean',
        'ai_flags'          => 'array',
        'repayment_month'   => 'date',
        'approved_at'       => 'datetime',
        'disbursed_at'      => 'datetime',
        'settled_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $advance) {
            $advance->uuid             ??= (string) Str::uuid();
            $advance->reference_number ??= 'SA-' . strtoupper(Str::random(8));
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRepayment::class, 'advance_id');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(StaffLoanPolicyViolation::class, 'advance_id');
    }

    public function affordabilityChecks(): HasMany
    {
        return $this->hasMany(StaffLoanAffordabilityCheck::class, 'advance_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('SalaryAdvance')
            ->logOnly(['reference_number', 'status', 'amount_requested', 'amount_approved', 'percentage_of_net', 'rejection_reason', 'risk_level'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }

    public function tapActivity(ActivityLog $activity, string $eventName): void
    {
        $user = Auth::user();
        $activity->causer_id   = $user?->id;
        $activity->causer_type = $user ? get_class($user) : null;
        $activity->properties  = $activity->properties->merge([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'old'        => $activity->properties['old'] ?? [],
            'attributes' => $activity->properties['attributes'] ?? [],
        ]);
    }

    public function calculatePercentageOfNet(): float
    {
        if (!$this->monthly_net_pay || $this->monthly_net_pay == 0) {
            return 0;
        }
        return round(($this->amount_requested / $this->monthly_net_pay) * 100, 2);
    }
}
