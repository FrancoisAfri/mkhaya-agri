<?php

namespace App\Modules\Operations\WarehouseManagement\Models;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\HR\Hr\Models\Attendance\AttendanceShift;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A person assigned to a warehouse, the shift they work and what they may do there (WMS-003). */
class WarehouseStaff extends Model
{
    use BelongsToCompany;

    protected $table = 'wms_warehouse_staff';

    protected $fillable = ['uuid', 'company_id', 'warehouse_id', 'user_id', 'shift_id', 'activities', 'is_active'];

    protected $casts = ['activities' => 'array', 'is_active' => 'boolean'];

    public const ACTIVITIES = ['RECEIVE', 'PUTAWAY', 'PICK', 'PACK', 'DISPATCH', 'COUNT', 'REPLENISH', 'RETURNS', 'SUPERVISE'];

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(FinWarehouse::class, 'warehouse_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(AttendanceShift::class, 'shift_id');
    }
}
