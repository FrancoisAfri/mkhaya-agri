<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One manual leave allocation run — reversible as a unit. */
class LeaveManualAllocationBatch extends Model
{
    use BelongsToCompany;

    public const STATUS_APPLIED  = 'applied';
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'leave_manual_allocation_batches';

    protected $fillable = [
        'uuid', 'company_id', 'scope_type', 'scope_id', 'scope_name',
        'leave_type_id', 'leave_type_name', 'action', 'days', 'hours_per_employee',
        'reason', 'total_employees', 'total_hours', 'status',
        'performed_by', 'performed_by_name',
        'reversed_by', 'reversed_by_name', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'days'               => 'decimal:2',
        'hours_per_employee' => 'decimal:2',
        'total_hours'        => 'decimal:2',
        'total_employees'    => 'integer',
        'reversed_at'        => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function items(): HasMany
    {
        return $this->hasMany(LeaveManualAllocationBatchItem::class, 'batch_id');
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
