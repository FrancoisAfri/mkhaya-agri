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

class StaffLoan extends Model
{
    use SoftDeletes;
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid', 'reference_number', 'employee_id', 'loan_type_id',
        'amount_requested', 'amount_approved', 'outstanding_balance',
        'monthly_installment', 'interest_rate', 'term_months', 'purpose',
        'employment_status', 'is_permanent', 'probation_completed', 'is_suspended',
        'monthly_net_pay', 'status', 'approved_by', 'rejected_by',
        'approved_at', 'rejected_at', 'disbursed_at', 'closed_at',
        'rejection_reason', 'next_payment_date', 'ai_flags', 'risk_score',
        'risk_level', 'affordability_score', 'notes', 'metadata',
    ];

    protected $casts = [
        'amount_requested'  => 'decimal:2',
        'amount_approved'   => 'decimal:2',
        'outstanding_balance'=> 'decimal:2',
        'monthly_installment'=> 'decimal:2',
        'interest_rate'     => 'decimal:2',
        'monthly_net_pay'   => 'decimal:2',
        'affordability_score'=> 'decimal:2',
        'is_permanent'      => 'boolean',
        'probation_completed'=> 'boolean',
        'is_suspended'      => 'boolean',
        'ai_flags'          => 'array',
        'metadata'          => 'array',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'disbursed_at'      => 'datetime',
        'closed_at'         => 'datetime',
        'next_payment_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $loan) {
            $loan->uuid             ??= (string) Str::uuid();
            $loan->reference_number ??= 'SL-' . strtoupper(Str::random(8));
        });
    }

    public function getRouteKeyName(): string { return 'uuid'; }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function loanType(): BelongsTo
    {
        return $this->belongsTo(StaffLoanType::class, 'loan_type_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(StaffLoanRepayment::class, 'loan_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(StaffLoanApproval::class, 'loan_id');
    }

    public function affordabilityChecks(): HasMany
    {
        return $this->hasMany(StaffLoanAffordabilityCheck::class, 'loan_id');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(StaffLoanPolicyViolation::class, 'loan_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('StaffLoan')
            ->logOnly(['reference_number', 'status', 'amount_requested', 'amount_approved', 'outstanding_balance', 'rejection_reason', 'risk_level'])
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDefaulted(): bool
    {
        return $this->status === 'defaulted';
    }

    public function repaymentProgressPct(): float
    {
        if (!$this->amount_approved || $this->amount_approved == 0) {
            return 0;
        }
        $paid = $this->amount_approved - $this->outstanding_balance;
        return round(($paid / $this->amount_approved) * 100, 1);
    }

    public function overdueInstallments(): int
    {
        return $this->repayments()->where('status', 'overdue')->count();
    }
}
