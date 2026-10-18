<?php

namespace App\Modules\HR\Leave\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One absence-upload run — reversible as a unit. */
class LeaveAbsenceUploadBatch extends Model
{
    use BelongsToCompany;

    public const STATUS_APPLIED  = 'applied';
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'leave_absence_upload_batches';

    protected $fillable = [
        'uuid', 'company_id', 'leave_type_id', 'leave_type_name', 'hours_per_day',
        'file_name', 'total_employees', 'total_days', 'total_hours', 'status',
        'performed_by', 'performed_by_name',
        'reversed_by', 'reversed_by_name', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'hours_per_day'   => 'decimal:2',
        'total_days'      => 'decimal:2',
        'total_hours'     => 'decimal:2',
        'total_employees' => 'integer',
        'reversed_at'     => 'datetime',
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
        return $this->hasMany(LeaveAbsenceUploadBatchItem::class, 'batch_id');
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
