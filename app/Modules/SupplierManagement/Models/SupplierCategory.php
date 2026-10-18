<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class SupplierCategory extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, KeepsDatabaseDefaults;

    protected $table = 'supplier_categories';

    protected $fillable = [
        'uuid', 'name', 'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierCategory $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(
            Supplier::class,
            'supplier_category_supplier',
            'supplier_category_id',
            'supplier_id'
        )->withTimestamps();
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
