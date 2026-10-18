<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One employee's share of a manual allocation batch. */
class LeaveManualAllocationBatchItem extends Model
{
    use BelongsToCompany;

    protected $table = 'leave_manual_allocation_batch_items';

    protected $fillable = [
        'uuid', 'company_id', 'batch_id', 'employee_id', 'employee_name',
        'employee_number', 'leave_type_id', 'hours',
        'balance_before', 'balance_after', 'is_reversed',
    ];

    protected $casts = [
        'hours'          => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'is_reversed'    => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LeaveManualAllocationBatch::class, 'batch_id');
    }
}
