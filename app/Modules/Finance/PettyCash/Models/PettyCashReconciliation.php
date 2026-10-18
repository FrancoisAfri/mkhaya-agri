<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity as ActivityLog;
use Spatie\Activitylog\Traits\LogsActivity;

class PettyCashReconciliation extends Model
{
    use LogsActivity;
    protected $table = 'petty_cash_reconciliations';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $fillable = [
        'uuid', 'float_id', 'reconciled_by', 'approved_by',
        'period_start', 'period_end',
        'opening_balance', 'total_replenishments', 'total_issued', 'total_receipts',
        'cash_on_hand', 'closing_balance', 'variance',
        'status', 'notes', 'rejection_reason', 'submitted_at', 'approved_at',
    ];

    protected $casts = [
        'period_start'          => 'date',
        'period_end'            => 'date',
        'opening_balance'       => 'decimal:2',
        'total_replenishments'  => 'decimal:2',
        'total_issued'          => 'decimal:2',
        'total_receipts'        => 'decimal:2',
        'cash_on_hand'          => 'decimal:2',
        'closing_balance'       => 'decimal:2',
        'variance'              => 'decimal:2',
        'submitted_at'          => 'datetime',
        'approved_at'           => 'datetime',
    ];

    public function float(): BelongsTo
    {
        return $this->belongsTo(PettyCashFloat::class, 'float_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PettyCashAttachment::class, 'reconciliation_id');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(PettyCashPolicyViolation::class, 'reconciliation_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('PettyCash')
            ->logOnly(['status', 'variance', 'cash_on_hand', 'closing_balance', 'rejection_reason'])
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

    public function hasShortage(): bool
    {
        return $this->variance < 0;
    }

    public function hasSurplus(): bool
    {
        return $this->variance > 0;
    }
}
