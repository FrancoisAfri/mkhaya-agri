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

class PettyCashFloat extends Model
{
    use SoftDeletes;
    use LogsActivity;
    protected $table = 'petty_cash_floats';

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
        'uuid', 'name', 'description', 'custodian_id', 'department_id',
        'opening_balance', 'current_balance', 'float_limit', 'replenishment_threshold',
        'currency', 'status', 'last_reconciled_at', 'notes',
    ];

    protected $casts = [
        'opening_balance'          => 'decimal:2',
        'current_balance'          => 'decimal:2',
        'float_limit'              => 'decimal:2',
        'replenishment_threshold'  => 'decimal:2',
        'last_reconciled_at'       => 'datetime',
    ];

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(PettyCashRequest::class, 'float_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class, 'float_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(PettyCashReconciliation::class, 'float_id');
    }

    public function policyViolations(): HasMany
    {
        return $this->hasMany(PettyCashPolicyViolation::class, 'float_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('PettyCash')
            ->logOnly(['name', 'float_limit', 'current_balance', 'status', 'custodian_id'])
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

    public function needsReplenishment(): bool
    {
        if ($this->replenishment_threshold === null) {
            return false;
        }

        return $this->current_balance <= $this->replenishment_threshold;
    }

    public function utilizationPercentage(): float
    {
        if ($this->float_limit == 0) {
            return 0;
        }

        return round((($this->float_limit - $this->current_balance) / $this->float_limit) * 100, 1);
    }
}
