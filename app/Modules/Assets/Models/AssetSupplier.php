<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * M11 — Asset Supplier
 * 
 * Supplier master data for procurement.
 */
class AssetSupplier extends Model
{
    protected $table = 'asset_suppliers';

    protected $fillable = [
        'uuid',
        'supplier_code',
        'supplier_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'tax_number',
        'payment_terms',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    // Relationships
    public function procurements(): HasMany
    {
        return $this->hasMany(AssetProcurement::class, 'supplier_id');
    }

    public function performanceRecords(): HasMany
    {
        return $this->hasMany(AssetSupplierPerformance::class, 'supplier_id');
    }
}
