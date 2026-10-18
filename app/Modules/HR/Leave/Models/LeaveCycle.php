<?php

namespace App\Modules\HR\Leave\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveCycle extends Model
{
    use LogsActivity;

    protected $table = 'leave_cycles';

    protected $fillable = [
        'name', 'start_date', 'end_date', 'cycle_type', 'year',
        'status', 'auto_advance', 'created_by',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'auto_advance' => 'boolean',
        'year'         => 'integer',
    ];

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(LeaveBalanceTransaction::class);
    }

    public static function active(): ?self
    {
        return self::where('status', 'active')->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('LeaveCycle')
            ->logOnly(['name', 'status', 'start_date', 'end_date'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
