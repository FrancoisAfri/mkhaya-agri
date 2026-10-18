<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeavePayoutRecord extends Model
{
    use SoftDeletes;
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeavePayout')
            ->logOnly(['employee_id', 'leave_type_id', 'hours_paid_out', 'amount',
                       'payout_date', 'payroll_reference', 'approved_by', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Leave payout #{$this->id} of {$this->hours_paid_out}h created for employee #{$this->employee_id}",
                'updated' => "Leave payout #{$this->id} updated (status: {$this->status})",
                'deleted' => "Leave payout #{$this->id} deleted",
                default   => "Leave payout #{$this->id}: {$e}",
            });
    }

    protected $table = 'leave_payout_records';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_cycle_id',
        'hours_paid_out', 'amount', 'payout_date',
        'payroll_reference', 'approved_by', 'status', 'notes',
    ];

    protected $casts = [
        'hours_paid_out' => 'decimal:2',
        'amount'         => 'decimal:2',
        'payout_date'    => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
