<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierPerformanceManagement extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_performance_management';

    protected $fillable = [
        'uuid', 'supplier_id', 'admin_id', 'admin_comments', 'admin_rating',
        'self_appraisal_percentage', 'is_active',
    ];

    protected $casts = [
        'admin_rating'              => 'integer',
        'self_appraisal_percentage' => 'decimal:2',
        'is_active'                 => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierPerformanceManagement $model) {
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
