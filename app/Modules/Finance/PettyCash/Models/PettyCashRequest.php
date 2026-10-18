<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Spatie\Activitylog\Traits\LogsActivity;

class PettyCashRequest extends Model
{
    use SoftDeletes;
    use LogsActivity;
    protected $table = 'petty_cash_requests';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->reference_number)) {
                $model->reference_number = 'PCR-' . strtoupper(Str::random(8));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid', 'reference_number', 'float_id', 'requestor_id', 'custodian_id', 'approver_id',
        'purpose', 'category', 'amount_requested', 'amount_approved', 'amount_issued',
        'amount_spent', 'amount_returned', 'status', 'rejection_reason', 'notes',
        'ai_flags', 'risk_score', 'risk_level', 'receipt_submitted', 'receipt_due_at',
        'requested_at', 'approved_at', 'rejected_at', 'issued_at', 'settled_at',
    ];

    protected $casts = [
        'amount_requested' => 'decimal:2',
        'amount_approved'  => 'decimal:2',
        'amount_issued'    => 'decimal:2',
        'amount_spent'     => 'decimal:2',
        'amount_returned'  => 'decimal:2',
        'risk_score'       => 'decimal:2',
        'ai_flags'         => 'array',
        'receipt_submitted'=> 'boolean',
        'receipt_due_at'   => 'datetime',
        'requested_at'     => 'datetime',
        'approved_at'      => 'datetime',
        'rejected_at'      => 'datetime',
        'issued_at'        => 'datetime',
        'settled_at'       => 'datetime',
    ];

    public function float(): BelongsTo
    {
        return $this->belongsTo(PettyCashFloat::class, 'float_id');
    }

    public function requestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requestor_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PettyCashApproval::class, 'request_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PettyCashAttachment::class, 'request_id');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(PettyCashPolicyViolation::class, 'request_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class, 'request_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('PettyCash')
            ->logOnly(['reference_number', 'purpose', 'category', 'amount_requested', 'amount_approved', 'amount_issued', 'status', 'rejection_reason'])
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

    public function isReceiptOverdue(): bool
    {
        if ($this->receipt_submitted || $this->receipt_due_at === null) {
            return false;
        }

        return now()->isAfter($this->receipt_due_at);
    }
}
