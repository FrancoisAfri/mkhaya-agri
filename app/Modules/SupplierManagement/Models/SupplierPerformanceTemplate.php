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
use App\Shared\Traits\KeepsDatabaseDefaults;

class SupplierPerformanceTemplate extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'supplier_performance_templates';

    protected $fillable = [
        'uuid', 'industry_id', 'name', 'status',
    ];

    /** Addressed by uuid, never by the row id. Templates are addressed from the builder and from every appraisal that used one. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierPerformanceTemplate $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(SupplierIndustryType::class, 'industry_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(SupplierQuestionLibrary::class, 'template_id');
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
