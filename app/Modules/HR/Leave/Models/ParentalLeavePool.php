<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ParentalLeavePool extends Model
{
    use LogsActivity;

    protected $table = 'parental_leave_pools';

    protected $fillable = [
        'primary_parent_id', 'secondary_parent_id',
        'child_birth_date', 'expected_birth_date', 'pool_type',
        'total_pool_hours', 'primary_allocated_hours', 'secondary_allocated_hours',
        'primary_used_hours', 'secondary_used_hours',
        'primary_lock_until', 'concurrent_leave_allowed',
        'uif_eligible', 'uif_reference',
        'status', 'legislation_version', 'approved_by',
    ];

    protected $casts = [
        'child_birth_date'          => 'date',
        'expected_birth_date'       => 'date',
        'primary_lock_until'        => 'date',
        'total_pool_hours'          => 'decimal:2',
        'primary_allocated_hours'   => 'decimal:2',
        'secondary_allocated_hours' => 'decimal:2',
        'primary_used_hours'        => 'decimal:2',
        'secondary_used_hours'      => 'decimal:2',
        'concurrent_leave_allowed'  => 'boolean',
        'uif_eligible'              => 'boolean',
    ];

    public function primaryParent(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'primary_parent_id');
    }

    public function secondaryParent(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'secondary_parent_id');
    }

    public function remainingPoolHours(): float
    {
        return (float) $this->total_pool_hours
            - (float) $this->primary_used_hours
            - (float) $this->secondary_used_hours;
    }

    public function isPrimaryLocked(): bool
    {
        return $this->primary_lock_until && now()->lessThan($this->primary_lock_until);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('ParentalLeavePool')
            ->logOnly(['status', 'primary_used_hours', 'secondary_used_hours'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
