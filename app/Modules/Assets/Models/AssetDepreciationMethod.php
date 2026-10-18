<?php

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * M14 — Depreciation Method Configuration
 * 
 * Defines available depreciation calculation methods.
 * Seeded with: straight_line, reducing_balance, double_declining, sum_of_years, units_of_production.
 */
class AssetDepreciationMethod extends Model
{
    protected $table = 'asset_depreciation_methods';

    protected $fillable = [
        'uuid',
        'method_code',           // straight_line | reducing_balance | double_declining | sum_of_years | units_of_production
        'method_name',
        'description',
        'formula',
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
}
