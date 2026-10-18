<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Enums\LeaveTransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Append-only ledger. Never update or delete rows.
 */
class LeaveBalanceTransaction extends Model
{
    use SoftDeletes;
    use HasFactory, LogsActivity;

    /**
     * Named explicitly: Laravel would look for the factory under
     * `Database\\Factories\\Modules\\HR\\Leave\\Models\\`, which is not where it lives.
     */
    protected static function newFactory(): \Database\Factories\LeaveBalanceTransactionFactory
    {
        return \Database\Factories\LeaveBalanceTransactionFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveBalanceTransaction')
            ->logOnly(['employee_id', 'leave_type_id', 'transaction_type', 'hours',
                       'balance_before', 'balance_after', 'reference_type', 'reference_id', 'description'])
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $e) => match($e) {
                'created' => "Balance transaction #{$this->id}: {$this->transaction_type?->value} {$this->hours}h for employee #{$this->employee_id}",
                default   => "Balance transaction #{$this->id}: {$e}",
            });
    }
    public $timestamps = false;

    protected $table = 'leave_balance_transactions';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'leave_cycle_id',
        'transaction_type', 'hours', 'balance_before', 'balance_after',
        'reference_type', 'reference_id', 'description', 'performed_by',
        'created_at',
    ];

    protected $casts = [
        'transaction_type' => LeaveTransactionType::class,
        'hours'            => 'decimal:2',
        'balance_before'   => 'decimal:2',
        'balance_after'    => 'decimal:2',
        'created_at'       => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HRPerson::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(LeaveCycle::class, 'leave_cycle_id');
    }

    public function scopeForEmployee($query, int $hrPersonId)
    {
        return $query->where('employee_id', $hrPersonId);
    }

    public function scopeForType($query, int $leaveTypeId)
    {
        return $query->where('leave_type_id', $leaveTypeId);
    }
}
