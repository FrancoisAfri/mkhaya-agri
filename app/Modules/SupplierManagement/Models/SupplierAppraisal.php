<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierAppraisal extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_appraisals';

    protected $fillable = [
        'uuid', 'supplier_id', 'industry_id', 'template_id', 'user_id',
        'appraisal_date', 'status', 'total_score',
    ];

    protected $casts = [
        'appraisal_date' => 'date',
        'total_score'    => 'decimal:2',
    ];

    /** Addressed by uuid, never by the row id. An appraisal is a record about a supplier and is linked to from a list; the row id in that URL says how many appraisals a tenant has run. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierAppraisal $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SupplierPerformanceTemplate::class, 'template_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(SupplierIndustryType::class, 'industry_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SupplierAppraisalResponse::class, 'appraisal_id');
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
