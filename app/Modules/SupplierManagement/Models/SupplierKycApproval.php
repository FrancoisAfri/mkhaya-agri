<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierKycApproval extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_kyc_approvals';

    protected $fillable = [
        'uuid', 'supplier_id', 'supplier_name', 'status', 'submitted_at', 'requested_by',
        'decided_by', 'decided_at', 'comment',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierKycApproval $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
