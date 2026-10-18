<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveProfileAllocation extends Model
{
    use SoftDeletes;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveProfileAllocation')
            ->logOnly(['min_hours', 'max_hours', 'default_hours', 'accrual_rate_hours_per_month',
                       'carry_over_max_hours', 'allow_negative', 'active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Leave allocation for profile #{$this->leave_profile_id} (type #{$this->leave_type_id}) created",
                'updated' => "Leave allocation for profile #{$this->leave_profile_id} (type #{$this->leave_type_id}) updated",
                'deleted' => "Leave allocation for profile #{$this->leave_profile_id} (type #{$this->leave_type_id}) deleted",
                default   => "Leave allocation #{$this->id}: {$e}",
            });
    }
    protected $table = 'leave_profile_allocations';

    protected $fillable = [
        'leave_profile_id', 'leave_type_id',
        'min_hours', 'max_hours', 'default_hours',
        'accrual_rate_hours_per_month', 'carry_over_max_hours',
        'allow_negative', 'negative_max_hours', 'active',
    ];

    protected $casts = [
        'min_hours'                   => 'decimal:2',
        'max_hours'                   => 'decimal:2',
        'default_hours'               => 'decimal:2',
        'accrual_rate_hours_per_month' => 'decimal:2',
        'carry_over_max_hours'        => 'decimal:2',
        'negative_max_hours'          => 'decimal:2',
        'allow_negative'              => 'boolean',
        'active'                      => 'boolean',
    ];

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
