<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierProduct extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity;

    protected $table = 'supplier_products';

    protected $fillable = [
        'uuid', 'supplier_id', 'item_name', 'item_price', 'active',
    ];

    protected $casts = [
        'item_price' => 'decimal:2',
        'active'     => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupplierProduct $model) {
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
